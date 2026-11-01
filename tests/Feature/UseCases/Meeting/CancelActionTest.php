<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\CancelAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 面談キャンセル Action の状態遷移・開始時刻の境界を検証する。
 */
class CancelActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserved_future_meeting_is_canceled_by_actor(): void
    {
        // Arrange
        $meeting = Meeting::factory()->reserved()->inFuture()->create();
        $actor = $meeting->student;

        // Act
        $canceled = app(CancelAction::class)($meeting, $actor);

        // Assert
        $this->assertSame(MeetingStatus::Canceled, $canceled->status);
        $this->assertSame($actor->id, $canceled->canceled_by_user_id);
        $this->assertNotNull($canceled->canceled_at);
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id, 'status' => MeetingStatus::Canceled->value]);
    }

    public function test_cancel_refunds_one_quota_to_student(): void
    {
        // Arrange
        $meeting = Meeting::factory()->reserved()->inFuture()->create();

        // Act
        app(CancelAction::class)($meeting, $meeting->student);

        // Assert
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $meeting->student_id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
            'related_meeting_id' => $meeting->id,
        ]);
    }

    public function test_cancel_notifies_coach_after_commit(): void
    {
        // Arrange
        $meeting = Meeting::factory()->reserved()->inFuture()->create();
        $student = $meeting->student;
        $coach = $meeting->coach;

        // Act
        app(CancelAction::class)($meeting, $student);

        // Assert
        $coachTypes = DB::table('notifications')
            ->where('notifiable_id', $coach->id)
            ->get()
            ->map(fn ($row) => json_decode($row->data, true)['notification_type'])
            ->all();
        $this->assertSame(['meeting_canceled'], $coachTypes);
    }

    public function test_non_reserved_meeting_cannot_be_canceled(): void
    {
        // Arrange
        $meeting = Meeting::factory()->completed()->create();

        // Act & Assert
        $this->expectException(MeetingStatusTransitionException::class);
        app(CancelAction::class)($meeting, User::factory()->student()->create());
    }

    public function test_meeting_already_started_cannot_be_canceled(): void
    {
        // Arrange
        $meeting = Meeting::factory()->reserved()->create([
            'scheduled_at' => now()->subHour()->setMinute(0)->setSecond(0),
        ]);

        // Act & Assert
        $this->expectException(MeetingAlreadyStartedException::class);
        app(CancelAction::class)($meeting, User::factory()->student()->create());
    }
}
