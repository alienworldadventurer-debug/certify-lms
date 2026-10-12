<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Notification;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\BusinessEventNotification;
use App\Services\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_message_notifies_only_other_active_room_members(): void
    {
        Notification::fake();
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $graduatedCoach = User::factory()->coach()->graduated()->create();
        $sender = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();

        foreach ([$student, $coach, $graduatedCoach, $sender] as $member) {
            ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $member->id]);
        }

        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        $message = ChatMessage::factory()->for($room)->for($sender, 'sender')->create();

        app(NotificationDeliveryService::class)->chatMessage($message);

        Notification::assertSentTo($student, BusinessEventNotification::class);
        Notification::assertSentTo($coach, BusinessEventNotification::class);
        Notification::assertNotSentTo($graduatedCoach, BusinessEventNotification::class);
        Notification::assertNotSentTo($sender, BusinessEventNotification::class);
        $sent = Notification::sent($student, BusinessEventNotification::class)->first();
        $this->assertSame('chat_message', $sent->toDatabase($student)['source_type']);
        $this->assertSame($message->id, $sent->toDatabase($student)['source_id']);
    }

    public function test_qa_reply_notifies_question_owner_but_not_self_answers(): void
    {
        Notification::fake();
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        $thread = QaThread::factory()->for($student, 'user')->for($certification)->create();
        $reply = QaReply::factory()->for($coach, 'user')->for($thread, 'thread')->create();

        app(NotificationDeliveryService::class)->qaReply($reply);

        Notification::assertSentTo($student, BusinessEventNotification::class);
        Notification::assertNotSentTo($coach, BusinessEventNotification::class);
        $sent = Notification::sent($student, BusinessEventNotification::class)->first();
        $this->assertSame('qa_reply', $sent->toDatabase($student)['source_type']);
        $this->assertSame($reply->id, $sent->toDatabase($student)['source_id']);

        Notification::fake();
        $selfReply = QaReply::factory()->for($student, 'user')->for($thread, 'thread')->create();
        app(NotificationDeliveryService::class)->qaReply($selfReply);

        Notification::assertNotSentTo($student, BusinessEventNotification::class);
    }

    public function test_meeting_reservation_notifies_assigned_coach_and_cancellation_notifies_other_party(): void
    {
        Notification::fake();
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(2)->startOfDay()->setTime(11, 15),
        ]);
        $service = app(NotificationDeliveryService::class);

        $service->meetingReserved($meeting);
        Notification::assertSentTo($coach, BusinessEventNotification::class);
        $sent = Notification::sent($coach, BusinessEventNotification::class)->first();
        $mail = $sent->toMail(new \stdClass);
        $this->assertStringContainsString('面談が予約されました', $mail->introLines[0]);
        $this->assertContains('面談開始日時：'.now()->addDays(2)->startOfDay()->setTime(11, 15)->format('Y/m/d H:i').'（日本時間）', $mail->introLines);
        $this->assertSame('内容を確認する', $mail->actionText);

        $meeting->update([
            'status' => MeetingStatus::Canceled,
            'canceled_by_user_id' => $coach->id,
            'canceled_at' => now(),
        ]);
        Notification::fake();
        $service->meetingCanceled($meeting->fresh(), $coach);
        Notification::assertSentTo($student, BusinessEventNotification::class);
        Notification::assertNotSentTo($coach, BusinessEventNotification::class);
    }

    public function test_mail_failures_are_logged_and_do_not_interrupt_database_notifications_for_other_recipients(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $sender = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($sender)->for($certification)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();

        foreach ([$student, $coach, $sender] as $member) {
            ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $member->id]);
        }

        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        $message = ChatMessage::factory()->for($room)->for($sender, 'sender')->create();

        Mail::shouldReceive('mailer')
            ->twice()
            ->andThrow(new RuntimeException('mail transport unavailable'));
        Log::shouldReceive('error')
            ->twice()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Business event notification email delivery failed.'
                && isset($context['notification_type'], $context['recipient_id'], $context['notification_id'], $context['exception']));

        app(NotificationDeliveryService::class)->chatMessage($message);

        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(1, $coach->notifications()->count());
        $this->assertSame(0, $sender->notifications()->count());
    }

    public function test_mail_escapes_markdown_markup_in_user_supplied_names(): void
    {
        $notification = new BusinessEventNotification([
            'notification_type' => 'qa_reply_received',
            'title' => '【Certify LMS】Review',
            'message' => '**ReviewName**さんから回答が届きました。',
            'body' => '',
            'greeting' => '**ReviewName**さん、いつもCertify LMSをご利用いただきありがとうございます。',
            'target_type' => 'qa_thread',
            'target_id' => (string) Str::ulid(),
            'url' => 'http://localhost/qa-board/example',
        ]);

        $html = (string) $notification->toMail(new \stdClass)->render();

        $this->assertStringContainsString('**ReviewName**', $html);
        $this->assertStringNotContainsString('<strong>ReviewName</strong>', $html);
    }
}
