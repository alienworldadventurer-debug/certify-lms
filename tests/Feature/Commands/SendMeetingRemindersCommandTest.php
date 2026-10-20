<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Tokyo'));
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_requires_valid_window(): void
    {
        $this->makeReservedMeeting();

        $this->artisan('notifications:send-meeting-reminders')->assertExitCode(1);
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'bad'])->assertExitCode(1);

        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    public function test_command_succeeds_when_all_deliveries_succeed(): void
    {
        Notification::fake();
        $this->makeReservedMeeting();

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutputToContain('配信 2 件')
            ->assertExitCode(0);
    }

    public function test_command_delivers_eve_window_for_tomorrow_meetings(): void
    {
        Mail::fake();
        $this->makeReservedMeeting('2026-10-21 10:00:00');

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutputToContain('配信 2 件')
            ->assertExitCode(0);

        $this->assertSame(2, DatabaseNotification::query()->count());
    }

    public function test_command_reports_skipped_count_on_rerun(): void
    {
        Mail::fake();
        $this->makeReservedMeeting();
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])->assertExitCode(0);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutputToContain('配信 0 件 / スキップ 2 件 / 失敗 0 件')
            ->assertExitCode(0);

        $this->assertSame(2, DatabaseNotification::query()->count());
    }

    public function test_command_fails_when_any_delivery_fails(): void
    {
        $this->makeReservedMeeting();

        Event::listen(NotificationSending::class, function (NotificationSending $event): void {
            if ($event->channel === 'mail') {
                throw new RuntimeException('mail down');
            }
        });

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutputToContain('失敗 2 件')
            ->assertExitCode(1);

        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    private function makeReservedMeeting(string $jstStart = '2026-10-20 13:00:00'): void
    {
        Meeting::factory()->reserved()
            ->forCoach(User::factory()->coach()->create())
            ->forStudent(User::factory()->student()->create())
            ->create(['scheduled_at' => CarbonImmutable::parse($jstStart, 'Asia/Tokyo')]);
    }
}
