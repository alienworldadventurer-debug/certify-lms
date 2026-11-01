<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Meeting\FetchAvailabilityAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 予約画面の空き枠取得 Action が、担当コーチの空き時間から枠を返すことを検証する。
 */
class FetchAvailabilityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_slots_for_assigned_coach_availability(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress()->create(), 'user')
            ->for($certification)->learning()->create();
        $monday = now()->startOfDay()->next(Carbon::MONDAY);

        // Act
        $slots = app(FetchAvailabilityAction::class)($enrollment, $monday);

        // Assert
        $this->assertNotEmpty($slots);
        $this->assertSame('09:00', $slots->first()['slot_start']->format('H:i'));
        $this->assertSame(1, $slots->first()['available_coach_count']);
    }

    public function test_returns_no_slots_when_no_coach_is_assigned(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress()->create(), 'user')
            ->for($certification)->learning()->create();
        $monday = now()->startOfDay()->next(Carbon::MONDAY);

        // Act
        $slots = app(FetchAvailabilityAction::class)($enrollment, $monday);

        // Assert
        $this->assertTrue($slots->isEmpty());
    }
}
