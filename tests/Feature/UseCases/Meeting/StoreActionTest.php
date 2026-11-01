<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\UseCases\Meeting\StoreAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 面談予約 Action の成功・枠外・コーチ不在・残回数不足を検証する。
 */
class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    private function setUpBookableEnrollment(int $maxMeetings = 3): array
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => $maxMeetings]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        return [$student, $coach, $enrollment];
    }

    private function nextMondayAt10(): Carbon
    {
        return now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);
    }

    public function test_reserves_meeting_with_available_coach_and_consumes_quota(): void
    {
        // Arrange
        [$student, $coach, $enrollment] = $this->setUpBookableEnrollment();

        // Act
        $meeting = app(StoreAction::class)($enrollment, $this->nextMondayAt10(), '相談したい');

        // Assert
        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertTrue($meeting->coach->is($coach));
        $this->assertTrue($meeting->student->is($student));
        $this->assertSame('相談したい', $meeting->topic);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'related_meeting_id' => $meeting->id,
        ]);
        $this->assertSame(
            $meeting->meeting_quota_transaction_id,
            MeetingQuotaTransaction::query()
                ->where('related_meeting_id', $meeting->id)
                ->where('type', MeetingQuotaTransactionType::Consumed)
                ->value('id'),
        );
    }

    public function test_throws_when_student_has_no_remaining_quota(): void
    {
        // Arrange
        [, , $enrollment] = $this->setUpBookableEnrollment(maxMeetings: 0);

        // Act & Assert
        $this->expectException(InsufficientMeetingQuotaException::class);
        app(StoreAction::class)($enrollment, $this->nextMondayAt10(), '相談したい');
    }

    public function test_throws_when_slot_is_outside_coach_availability(): void
    {
        // Arrange
        [, , $enrollment] = $this->setUpBookableEnrollment();

        // Act & Assert
        $this->expectException(MeetingOutOfAvailabilityException::class);
        app(StoreAction::class)($enrollment, $this->nextMondayAt10()->setHour(20), '相談したい');
    }

    public function test_coach_unassigned_certification_is_rejected_as_out_of_availability(): void
    {
        // Arrange: 担当コーチがいない講座では空き枠が生成されないため、validateSlot で弾かれる
        [$student] = $this->setUpBookableEnrollment();
        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->for(Certification::factory()->published()->create())
            ->learning()
            ->create();

        // Act & Assert
        $this->expectException(MeetingOutOfAvailabilityException::class);
        app(StoreAction::class)($enrollment, $this->nextMondayAt10(), '相談したい');
    }

    public function test_rejected_reservation_does_not_create_meeting_or_consume_quota(): void
    {
        // Arrange
        [$student, , $enrollment] = $this->setUpBookableEnrollment(maxMeetings: 0);

        // Act
        try {
            app(StoreAction::class)($enrollment, $this->nextMondayAt10(), '相談したい');
        } catch (InsufficientMeetingQuotaException) {
            // 期待される例外のため握りつぶす
        }

        // Assert
        $this->assertSame(0, Meeting::query()->where('student_id', $student->id)->count());
        $this->assertSame(0, MeetingQuotaTransaction::query()->where('user_id', $student->id)->count());
    }
}
