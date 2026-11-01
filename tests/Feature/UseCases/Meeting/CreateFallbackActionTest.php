<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Meeting\CreateFallbackAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 予約画面の受講登録フォールバック一覧 Action の対象ステータス・本人限定を検証する。
 */
class CreateFallbackActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_learning_and_passed_enrollments_of_user_only(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $learning = Enrollment::factory()->learning()->create(['user_id' => $student->id]);
        $passed = Enrollment::factory()->create([
            'user_id' => $student->id,
            'status' => EnrollmentStatus::Passed->value,
        ]);
        $failed = Enrollment::factory()->create([
            'user_id' => $student->id,
            'status' => EnrollmentStatus::Failed->value,
        ]);
        Enrollment::factory()->learning()->create();

        // Act
        $result = app(CreateFallbackAction::class)($student);

        // Assert
        $ids = $result->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$learning->id, $passed->id], $ids);
        $this->assertNotContains($failed->id, $ids);
    }

    public function test_returns_empty_collection_when_user_is_null(): void
    {
        // Arrange
        Enrollment::factory()->learning()->create();

        // Act
        $result = app(CreateFallbackAction::class)(null);

        // Assert
        $this->assertTrue($result->isEmpty());
    }
}
