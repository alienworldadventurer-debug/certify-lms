<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\IndexAsCoachAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * コーチ向け面談一覧 Action の担当限定・絞り込み・並び順を検証する。
 */
class IndexAsCoachActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_meetings_assigned_to_coach_are_listed(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $own = Meeting::factory()->reserved()->inFuture()->forCoach($coach)->create();
        $others = Meeting::factory()->reserved()->inFuture()->forCoach($otherCoach)->create();

        // Act
        $result = app(IndexAsCoachAction::class)($coach, []);

        // Assert
        $ids = $result->getCollection()->pluck('id')->all();
        $this->assertSame([$own->id], $ids);
        $this->assertNotContains($others->id, $ids);
    }

    public function test_upcoming_is_sorted_ascending(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $later = Meeting::factory()->reserved()->forCoach($coach)->create([
            'scheduled_at' => now()->addDays(5)->startOfHour(),
        ]);
        $sooner = Meeting::factory()->reserved()->forCoach($coach)->create([
            'scheduled_at' => now()->addDays(2)->startOfHour(),
        ]);

        // Act
        $result = app(IndexAsCoachAction::class)($coach, ['filter' => 'upcoming']);

        // Assert
        $this->assertSame([$sooner->id, $later->id], $result->getCollection()->pluck('id')->all());
    }

    public function test_past_returns_completed_and_canceled_sorted_descending(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $older = Meeting::factory()->completed()->forCoach($coach)->create([
            'scheduled_at' => now()->subDays(10)->startOfHour(),
        ]);
        $newer = Meeting::factory()->canceled()->forCoach($coach)->create([
            'scheduled_at' => now()->subDays(2)->startOfHour(),
        ]);
        $upcoming = Meeting::factory()->reserved()->inFuture()->forCoach($coach)->create();

        // Act
        $result = app(IndexAsCoachAction::class)($coach, ['filter' => 'past']);

        // Assert
        $this->assertSame([$newer->id, $older->id], $result->getCollection()->pluck('id')->all());
        $this->assertNotContains($upcoming->id, $result->getCollection()->pluck('id')->all());
    }

    public function test_student_filter_narrows_results(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $targetStudent = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $target = Meeting::factory()->reserved()->inFuture()->forCoach($coach)->forStudent($targetStudent)->create();
        Meeting::factory()->reserved()->inFuture()->forCoach($coach)->forStudent($otherStudent)->create();

        // Act
        $result = app(IndexAsCoachAction::class)($coach, ['student' => $targetStudent->id]);

        // Assert
        $this->assertSame([$target->id], $result->getCollection()->pluck('id')->all());
    }
}
