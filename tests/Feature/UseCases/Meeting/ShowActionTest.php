<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談詳細 Action が表示に必要なリレーションを読み込むことを検証する。
 */
class ShowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_required_relations(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->reserved()->inFuture()->forCoach($coach)->forStudent($student)->create();

        // Act
        $result = app(ShowAction::class)($meeting);

        // Assert
        $this->assertTrue($result->relationLoaded('enrollment'));
        $this->assertTrue($result->enrollment->relationLoaded('certification'));
        $this->assertTrue($result->relationLoaded('coach'));
        $this->assertTrue($result->relationLoaded('student'));
        $this->assertTrue($result->relationLoaded('canceledBy'));
        $this->assertTrue($result->relationLoaded('meetingMemo'));
        $this->assertSame($coach->id, $result->coach->id);
        $this->assertSame($student->id, $result->student->id);
    }
}
