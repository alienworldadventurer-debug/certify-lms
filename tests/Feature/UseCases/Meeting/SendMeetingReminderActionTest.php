<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\UseCases\Meeting\SendMeetingReminderAction;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class SendMeetingReminderActionTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // 現在: 2026-10-20 12:00 JST 固定（Carbon / CarbonImmutable 両方を固定する）
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Tokyo'));
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Asia/Tokyo'));

        $this->coach = User::factory()->coach()->create(['name' => '山田']);
        $this->student = User::factory()->student()->create(['name' => '佐藤']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function meetingAt(string $jst, array $overrides = []): Meeting
    {
        return Meeting::factory()->reserved()->forCoach($this->coach)->forStudent($this->student)->create(array_merge([
            'scheduled_at' => CarbonImmutable::parse($jst, 'Asia/Tokyo'),
        ], $overrides));
    }

    private function runWindow(MeetingReminderWindow $window): array
    {
        return app(SendMeetingReminderAction::class)($window);
    }

    public function test_eve_window_delivers_to_student_and_coach_for_tomorrow_meetings(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-21 10:00:00');

        $counts = $this->runWindow(MeetingReminderWindow::Eve);

        $this->assertSame(['delivered' => 2, 'skipped' => 0, 'failed' => 0], $counts);
        Notification::assertSentTo($this->student, MeetingReminderNotification::class);
        Notification::assertSentTo($this->coach, MeetingReminderNotification::class);
    }

    public function test_eve_window_ignores_meetings_outside_tomorrow(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-20 18:00:00');
        $this->meetingAt('2026-10-22 09:00:00');

        $counts = $this->runWindow(MeetingReminderWindow::Eve);

        $this->assertSame(['delivered' => 0, 'skipped' => 0, 'failed' => 0], $counts);
        Notification::assertNothingSent();
    }

    public function test_one_hour_before_window_includes_meeting_within_60_minutes(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-20 13:00:00');

        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(2, $counts['delivered']);
    }

    public function test_one_hour_before_window_excludes_meeting_beyond_60_minutes(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-20 13:01:00');

        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(0, $counts['delivered']);
        Notification::assertNothingSent();
    }

    public function test_past_and_non_reserved_meetings_are_not_delivered(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-20 11:30:00');
        $this->meetingAt('2026-10-20 12:30:00', [
            'status' => MeetingStatus::Canceled->value,
            'canceled_at' => now(),
            'canceled_by_user_id' => $this->student->id,
        ]);

        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(0, $counts['delivered']);
        Notification::assertNothingSent();
    }

    public function test_withdrawn_recipient_is_skipped(): void
    {
        Notification::fake();
        $this->student->update(['status' => UserStatus::Withdrawn->value]);
        $this->meetingAt('2026-10-20 13:00:00');

        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(1, $counts['delivered']);
        Notification::assertNotSentTo($this->student, MeetingReminderNotification::class);
        Notification::assertSentTo($this->coach, MeetingReminderNotification::class);
    }

    public function test_second_run_does_not_deliver_duplicates(): void
    {
        $this->meetingAt('2026-10-20 13:00:00');

        $this->runWindow(MeetingReminderWindow::OneHourBefore);
        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(['delivered' => 0, 'skipped' => 2, 'failed' => 0], $counts);
        $this->assertSame(1, $this->student->notifications()->count());
        $this->assertSame(1, $this->coach->notifications()->count());
    }

    public function test_mail_failure_rolls_back_notification_and_is_retried_on_next_run(): void
    {
        $meeting = $this->meetingAt('2026-10-20 13:00:00');

        Event::listen(NotificationSending::class, function (NotificationSending $event): void {
            if ($event->channel === 'mail' && $event->notifiable->is($this->student)) {
                throw new RuntimeException('mail down');
            }
        });

        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(['delivered' => 1, 'skipped' => 0, 'failed' => 1], $counts);
        $this->assertSame(0, $this->student->notifications()->count());
        $this->assertSame(1, $this->coach->notifications()->count());

        Event::forget(NotificationSending::class);
        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(['delivered' => 1, 'skipped' => 1, 'failed' => 0], $counts);
        $this->assertSame(1, $this->student->notifications()->count());
        $this->assertSame((string) $meeting->id, $this->student->notifications()->first()->data['source_id']);
    }

    public function test_database_notification_contains_expected_content_without_start_time_line(): void
    {
        Mail::fake();
        $this->meetingAt('2026-10-20 13:00:00', ['topic' => 'キャリア相談']);

        $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $data = $this->student->notifications()->first()->data;

        $this->assertSame('【Certify LMS】1時間前リマインド', $data['title']);
        $this->assertSame('2026/10/20 13:00に予定されている山田コーチとの面談', $data['message']);
        $this->assertStringContainsString('日時：10月20日(火) 13:00〜14:00', $data['body']);
        $this->assertStringContainsString('相談内容：キャリア相談', $data['body']);
        $this->assertStringNotContainsString('面談開始日時', $data['body']);
    }
}
