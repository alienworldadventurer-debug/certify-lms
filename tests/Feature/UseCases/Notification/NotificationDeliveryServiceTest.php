<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Notification;

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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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

        Notification::fake();
        $service->meetingCanceled($meeting, $coach);
        Notification::assertSentTo($student, BusinessEventNotification::class);
        Notification::assertNotSentTo($coach, BusinessEventNotification::class);
    }
}
