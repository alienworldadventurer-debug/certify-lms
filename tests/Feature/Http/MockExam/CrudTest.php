<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('validPassingScores')]
    public function test_admin_can_create_mock_exam_as_draft(int $passingScore): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.mock-exams.store'), [
                'certification_id' => $cert->id,
                'title' => '第 1 回 本番形式',
                'description' => '本番試験を想定した模試',
                'order' => 0,
                'passing_score' => $passingScore,
            ]);

        $mockExam = MockExam::firstOrFail();
        $response->assertRedirect(route('admin.mock-exams.show', $mockExam))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '模試マスタを作成しました。');
        $this->assertDatabaseCount('mock_exams', 1);
        $this->assertSame($passingScore, $mockExam->passing_score);
        $this->assertFalse($mockExam->is_published);
        $this->assertNull($mockExam->published_at);
        $this->assertSame($admin->id, $mockExam->created_by_user_id);
        $this->assertSame($admin->id, $mockExam->updated_by_user_id);
    }

    #[DataProvider('invalidPassingScorePayloads')]
    public function test_admin_cannot_create_mock_exam_with_invalid_passing_score(array $passingScorePayload): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        $response = $this->actingAs($admin)
            ->from(route('admin.mock-exams.create'))
            ->post(route('admin.mock-exams.store'), array_merge([
                'certification_id' => $cert->id,
                'title' => '範囲外の合格点',
                'description' => null,
                'order' => 0,
            ], $passingScorePayload));

        $response->assertRedirect(route('admin.mock-exams.create'))
            ->assertSessionHasErrors('passing_score')
            ->assertSessionMissing('success');
        $this->assertDatabaseCount('mock_exams', 0);
    }

    public function test_store_silently_drops_time_limit_minutes_field(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        // E-3: time_limit_minutes は受け取らない(silently drop)
        $response = $this->actingAs($admin)
            ->post(route('admin.mock-exams.store'), [
                'certification_id' => $cert->id,
                'title' => '第 1 回',
                'description' => null,
                'order' => 0,
                'passing_score' => 60,
                'time_limit_minutes' => 90,
            ]);

        $mockExam = MockExam::firstOrFail();
        $response->assertRedirect(route('admin.mock-exams.show', $mockExam))
            ->assertSessionHas('success', '模試マスタを作成しました。');
        $this->assertArrayNotHasKey('time_limit_minutes', $mockExam->getAttributes());
    }

    public function test_coach_cannot_create_mock_exam_for_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCert = Certification::factory()->published()->create();

        $this->actingAs($coach)
            ->postJson(route('admin.mock-exams.store'), [
                'certification_id' => $otherCert->id,
                'title' => 'unauthorized',
                'description' => null,
                'order' => 0,
                'passing_score' => 60,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('mock_exams', 0);
    }

    public function test_student_cannot_access_admin_mock_exams_index(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('admin.mock-exams.index'))
            ->assertForbidden();
    }

    #[DataProvider('validPassingScores')]
    public function test_admin_can_update_mock_exam_without_changing_certification(int $passingScore): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->create();
        $originalCertId = $mockExam->certification_id;

        $this->actingAs($admin)
            ->put(route('admin.mock-exams.update', $mockExam), [
                'title' => '改題後',
                'description' => '更新された説明',
                'order' => 5,
                'passing_score' => $passingScore,
            ])
            ->assertRedirect(route('admin.mock-exams.show', $mockExam))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '模試マスタを更新しました。');

        $mockExam->refresh();
        $this->assertSame('改題後', $mockExam->title);
        $this->assertSame($passingScore, $mockExam->passing_score);
        $this->assertSame($originalCertId, $mockExam->certification_id);
    }

    #[DataProvider('invalidPassingScorePayloads')]
    public function test_admin_cannot_update_mock_exam_with_invalid_passing_score(array $passingScorePayload): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->create(['passing_score' => 60]);
        $originalAttributes = $mockExam->fresh()->getAttributes();

        $response = $this->actingAs($admin)
            ->from(route('admin.mock-exams.edit', $mockExam))
            ->put(route('admin.mock-exams.update', $mockExam), array_merge([
                'title' => '保存されないタイトル',
                'description' => '保存されない説明',
                'order' => 5,
            ], $passingScorePayload));

        $response->assertRedirect(route('admin.mock-exams.edit', $mockExam))
            ->assertSessionHasErrors('passing_score')
            ->assertSessionMissing('success');
        $this->assertSame($originalAttributes, $mockExam->fresh()->getAttributes());
    }

    public function test_guest_cannot_create_mock_exam(): void
    {
        $cert = Certification::factory()->published()->create();

        $this->post(route('admin.mock-exams.store'), [
            'certification_id' => $cert->id,
            'title' => '未認証の作成',
            'order' => 0,
            'passing_score' => 60,
        ])
            ->assertRedirect(route('login'))
            ->assertSessionMissing('success');

        $this->assertDatabaseCount('mock_exams', 0);
    }

    public function test_guest_cannot_update_mock_exam(): void
    {
        $mockExam = MockExam::factory()->create();
        $originalAttributes = $mockExam->fresh()->getAttributes();

        $this->put(route('admin.mock-exams.update', $mockExam), [
            'title' => '未認証の更新',
            'description' => '保存されない説明',
            'order' => 5,
            'passing_score' => 80,
        ])
            ->assertRedirect(route('login'))
            ->assertSessionMissing('success');

        $this->assertSame($originalAttributes, $mockExam->fresh()->getAttributes());
    }

    public function test_coach_cannot_update_mock_exam_for_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $mockExam = MockExam::factory()->create();
        $originalAttributes = $mockExam->fresh()->getAttributes();

        $this->actingAs($coach)
            ->putJson(route('admin.mock-exams.update', $mockExam), [
                'title' => '担当外の更新',
                'description' => '保存されない説明',
                'order' => 5,
                'passing_score' => 80,
            ])
            ->assertForbidden();

        $this->assertSame($originalAttributes, $mockExam->fresh()->getAttributes());
    }

    public function test_destroy_rejects_published_mock_exam(): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->published()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.mock-exams.destroy', $mockExam))
            ->assertStatus(409);

        $this->assertDatabaseHas('mock_exams', ['id' => $mockExam->id]);
    }

    public function test_destroy_rejects_when_active_session_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->create();
        MockExamSession::factory()->forMockExam($mockExam)->inProgress()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.mock-exams.destroy', $mockExam))
            ->assertStatus(409);

        $this->assertDatabaseHas('mock_exams', ['id' => $mockExam->id]);
    }

    public function test_destroy_succeeds_when_unpublished_and_no_session(): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->create();

        $this->actingAs($admin)
            ->delete(route('admin.mock-exams.destroy', $mockExam))
            ->assertRedirect(route('admin.mock-exams.index'))
            ->assertSessionHas('success', '模試マスタを削除しました。');

        $this->assertDatabaseMissing('mock_exams', ['id' => $mockExam->id]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function validPassingScores(): array
    {
        return [
            'minimum' => [1],
            'typical' => [60],
            'maximum' => [100],
        ];
    }

    /**
     * @return array<string, array{array{passing_score?: mixed}}>
     */
    public static function invalidPassingScorePayloads(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['passing_score' => '']],
            'null' => [['passing_score' => null]],
            'zero' => [['passing_score' => 0]],
            'negative' => [['passing_score' => -1]],
            'fraction' => [['passing_score' => 60.5]],
            'text' => [['passing_score' => 'abc']],
            'over maximum' => [['passing_score' => 101]],
            'reported bug' => [['passing_score' => 150]],
        ];
    }
}
