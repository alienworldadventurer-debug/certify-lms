<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講生向け面談一覧 Action の絞り込み・並び順・本人限定を検証する。
 */
class IndexActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_upcoming_returns_only_future_reserved_meetings_of_student(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $upcoming = Meeting::factory()->reserved()->inFuture()->forCoach($coach)->forStudent($student)->create();
        $past = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();
        $otherStudent = User::factory()->student()->create();
        $others = Meeting::factory()->reserved()->inFuture()->forCoach($coach)->forStudent($otherStudent)->create();

        // Act
        $result = app(IndexAction::class)($student, 'upcoming');

        // Assert
        $ids = $result->getCollection()->pluck('id')->all();
        $this->assertSame([$upcoming->id], $ids);
        $this->assertNotContains($past->id, $ids);
        $this->assertNotContains($others->id, $ids);
    }

    public function test_past_returns_canceled_and_completed_meetings_only(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $completed = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();
        $canceled = Meeting::factory()->canceled()->forCoach($coach)->forStudent($student)->create();
        $upcoming = Meeting::factory()->reserved()->inFuture()->forCoach($coach)->forStudent($student)->create();

        // Act
        $result = app(IndexAction::class)($student, 'past');

        // Assert
        $ids = $result->getCollection()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$completed->id, $canceled->id], $ids);
        $this->assertNotContains($upcoming->id, $ids);
    }

    public function test_all_returns_every_own_meeting_ordered_by_scheduled_at_desc(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $older = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(10)->startOfHour(),
        ]);
        $newer = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        // Act
        $result = app(IndexAction::class)($student, 'all');

        // Assert
        $this->assertSame([$newer->id, $older->id], $result->getCollection()->pluck('id')->all());
    }

    public function test_unknown_filter_falls_back_to_upcoming(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $upcoming = Meeting::factory()->reserved()->inFuture()->forCoach($coach)->forStudent($student)->create();
        Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();

        // Act
        $result = app(IndexAction::class)($student, 'unknown');

        // Assert
        $this->assertSame([$upcoming->id], $result->getCollection()->pluck('id')->all());
    }
}
