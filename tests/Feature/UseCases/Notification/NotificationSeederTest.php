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
use Database\Seeders\NotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_events_match_their_recipients_and_meeting_state(): void
    {
        $student = User::factory()->student()->inProgress()->create([
            'email' => 'student@certify-lms.test',
        ]);
        $coach = User::factory()->coach()->inProgress()->create([
            'email' => 'coach@certify-lms.test',
        ]);
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();

        foreach ([$student, $coach] as $member) {
            ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $member->id]);
        }

        ChatMessage::factory()->for($room)->for($student, 'sender')->create();
        ChatMessage::factory()->for($room)->for($coach, 'sender')->create();
        $thread = QaThread::factory()->for($student, 'user')->for($certification)->create();
        QaReply::factory()->for($coach, 'user')->for($thread, 'thread')->create();

        $reservedMeeting = Meeting::factory()
            ->reserved()
            ->forEnrollment($enrollment)
            ->forCoach($coach)
            ->create(['scheduled_at' => now()->addDays(2)]);
        $canceledMeeting = Meeting::factory()
            ->canceled()
            ->forEnrollment($enrollment)
            ->forCoach($coach)
            ->create([
                'scheduled_at' => now()->addDays(3),
                'canceled_by_user_id' => $coach->id,
            ]);

        (new NotificationSeeder)->run();

        $studentEvents = $student->notifications()->get()->pluck('data');
        $coachEvents = $coach->notifications()->get()->pluck('data');

        $this->assertContains('qa_reply_received', $studentEvents->pluck('notification_type'));
        $this->assertNotContains('qa_reply_received', $coachEvents->pluck('notification_type'));
        $this->assertContains('meeting_canceled', $studentEvents->pluck('notification_type'));
        $this->assertContains('meeting_reserved', $coachEvents->pluck('notification_type'));

        foreach ($studentEvents->where('notification_type', 'meeting_canceled') as $event) {
            $this->assertSame($canceledMeeting->id, $event['target_id']);
        }
        foreach ($coachEvents->where('notification_type', 'meeting_reserved') as $event) {
            $this->assertSame($reservedMeeting->id, $event['target_id']);
        }

        $this->assertSame(MeetingStatus::Canceled, $canceledMeeting->fresh()->status);
    }
}
