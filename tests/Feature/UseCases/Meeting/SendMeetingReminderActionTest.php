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
use Illuminate\Support\Facades\Log;
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

    public function test_eve_window_boundaries_cover_tomorrow_from_0000_to_2359(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-20 23:59:59');
        $this->meetingAt('2026-10-21 00:00:00');
        $this->meetingAt('2026-10-21 23:59:59');
        $this->meetingAt('2026-10-22 00:00:00');

        $counts = $this->runWindow(MeetingReminderWindow::Eve);

        $this->assertSame(['delivered' => 4, 'skipped' => 0, 'failed' => 0], $counts);
    }

    public function test_one_hour_before_window_includes_exactly_60_minutes_and_excludes_exactly_now(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-20 12:00:00');
        $this->meetingAt('2026-10-20 13:00:00');

        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(['delivered' => 2, 'skipped' => 0, 'failed' => 0], $counts);
    }

    public function test_completed_meeting_is_not_delivered(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-20 12:30:00', [
            'status' => MeetingStatus::Completed->value,
            'completed_at' => now(),
        ]);

        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(['delivered' => 0, 'skipped' => 0, 'failed' => 0], $counts);
        Notification::assertNothingSent();
    }

    public function test_same_meeting_gets_one_notification_per_window(): void
    {
        $this->meetingAt('2026-10-20 12:30:00');
        $this->meetingAt('2026-10-21 10:00:00');

        $this->runWindow(MeetingReminderWindow::OneHourBefore);
        $this->runWindow(MeetingReminderWindow::Eve);
        $this->runWindow(MeetingReminderWindow::Eve);

        $windows = $this->student->notifications()->get()->pluck('data.reminder_window')->sort()->values()->all();
        $this->assertSame(['eve', 'one_hour_before'], $windows);
        $this->assertSame(2, $this->coach->notifications()->count());
    }

    public function test_invited_and_soft_deleted_recipients_are_skipped_but_counterpart_is_delivered(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-20 12:30:00');
        $this->meetingAt('2026-10-20 12:45:00', [
            'coach_id' => User::factory()->coach()->create()->id,
            'student_id' => ($invited = User::factory()->student()->invited()->create())->id,
        ]);
        $deletedCoach = User::factory()->coach()->create();
        $okStudent = User::factory()->student()->create();
        $this->meetingAt('2026-10-20 12:50:00', ['coach_id' => $deletedCoach->id, 'student_id' => $okStudent->id]);
        $deletedCoach->delete();

        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(['delivered' => 4, 'skipped' => 0, 'failed' => 0], $counts);
        Notification::assertNotSentTo($invited, MeetingReminderNotification::class);
        Notification::assertNotSentTo($deletedCoach, MeetingReminderNotification::class);
        Notification::assertSentTo($okStudent, MeetingReminderNotification::class);
    }

    public function test_graduated_recipient_receives_reminder(): void
    {
        Notification::fake();
        $this->student->update(['status' => UserStatus::Graduated->value]);
        $this->meetingAt('2026-10-20 12:30:00');

        $this->runWindow(MeetingReminderWindow::OneHourBefore);

        Notification::assertSentTo($this->student, MeetingReminderNotification::class);
    }

    public function test_admin_does_not_receive_reminder(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $this->meetingAt('2026-10-20 12:30:00', ['canceled_by_user_id' => $admin->id]);

        $this->runWindow(MeetingReminderWindow::OneHourBefore);

        Notification::assertNotSentTo($admin, MeetingReminderNotification::class);
        Notification::assertCount(2);
    }

    public function test_eve_notification_has_window_title_and_counterpart_labels_per_recipient(): void
    {
        Mail::fake();
        $meeting = $this->meetingAt('2026-10-21 10:00:00');

        $this->runWindow(MeetingReminderWindow::Eve);

        $studentNotification = $this->student->notifications()->first();
        $coachNotification = $this->coach->notifications()->first();
        $this->assertSame('【Certify LMS】前日リマインド', $studentNotification->data['title']);
        $this->assertSame('2026/10/21 10:00に予定されている山田コーチとの面談', $studentNotification->data['message']);
        $this->assertSame('2026/10/21 10:00に予定されている佐藤様との面談', $coachNotification->data['message']);
        $this->assertSame([
            'notification_type' => 'meeting_reminder',
            'target_type' => 'meeting',
            'target_id' => (string) $meeting->id,
            'source_type' => 'meeting',
            'source_id' => (string) $meeting->id,
            'url' => route('meetings.show', $meeting),
            'reminder_window' => 'eve',
        ], collect($studentNotification->data)->only([
            'notification_type', 'target_type', 'target_id', 'source_type', 'source_id', 'url', 'reminder_window',
        ])->all());
        $this->assertNull($studentNotification->read_at);
    }

    public function test_mail_contains_subject_schedule_certification_topic_and_button_to_meeting_detail(): void
    {
        Notification::fake();
        $meeting = $this->meetingAt('2026-10-20 12:30:00', ['topic' => "転職の相談\n学習計画も確認したい"]);

        $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $sent = Notification::sent($this->student, MeetingReminderNotification::class)->first();
        $mail = $sent->toMail($this->student);
        $certificationName = $meeting->enrollment->certification->name;

        $this->assertSame('【Certify LMS】1時間前リマインド', $mail->subject);
        $this->assertContains('日時：10月20日(火) 12:30〜13:30', $mail->introLines);
        $this->assertContains('資格：'.$certificationName, $mail->introLines);
        $this->assertContains('相談内容：転職の相談', $mail->introLines);
        $this->assertContains('学習計画も確認したい', $mail->introLines);
        $this->assertSame('内容を確認する', $mail->actionText);
        $this->assertSame(route('meetings.show', $meeting), $mail->actionUrl);
    }

    public function test_mail_has_no_default_greeting(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-20 12:30:00');

        $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $mail = Notification::sent($this->student, MeetingReminderNotification::class)->first()->toMail($this->student);

        $this->assertStringNotContainsString('Hello', $mail->render()->toHtml());
    }

    public function test_mail_escapes_markdown_markup_in_topic(): void
    {
        Notification::fake();
        $this->meetingAt('2026-10-20 12:30:00', ['topic' => '**強調**と[リンク](http://evil.example)']);

        $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $mail = Notification::sent($this->student, MeetingReminderNotification::class)->first()->toMail($this->student);
        $html = (string) $mail->render();

        $this->assertStringContainsString('**強調**', $html);
        $this->assertStringNotContainsString('<strong>強調</strong>', $html);
        $this->assertStringNotContainsString('href="http://evil.example"', $html);
    }

    public function test_saved_notification_is_not_rewritten_after_names_change(): void
    {
        Mail::fake();
        $this->meetingAt('2026-10-20 12:30:00');
        $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->coach->update(['name' => '変更後']);

        $this->assertSame(
            '2026/10/20 12:30に予定されている山田コーチとの面談',
            $this->student->notifications()->first()->data['message'],
        );
    }

    public function test_failure_is_logged_and_other_recipient_is_still_delivered(): void
    {
        Log::spy();
        $meeting = $this->meetingAt('2026-10-20 12:30:00');
        Event::listen(NotificationSending::class, function (NotificationSending $event): void {
            if ($event->channel === 'mail' && $event->notifiable->is($this->student)) {
                throw new RuntimeException('mail down');
            }
        });

        $counts = $this->runWindow(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(['delivered' => 1, 'skipped' => 0, 'failed' => 1], $counts);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Meeting reminder delivery failed.'
                && $context['meeting_id'] === $meeting->id
                && $context['recipient_id'] === $this->student->id
                && $context['reminder_window'] === 'one_hour_before',
        );
    }
}
