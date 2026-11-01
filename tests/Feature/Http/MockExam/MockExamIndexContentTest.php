<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\MockExamQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * 模試マスタ一覧 (`admin.mock-exams.index`) の表示内容と並びを検証する Feature テスト。
 * Eager Loading / withCount による最適化の前後で、各行の関連情報と並びが変わらないことを担保する。
 */
class MockExamIndexContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_related_data_and_sorts_by_order_within_certification(): void
    {
        // Arrange: 同一資格の模試を order 順ではない順序で作成し、更新者と問題数を設定
        $admin = User::factory()->admin()->create();
        $updater = User::factory()->admin()->create(['name' => '更新者テスト']);
        $certification = Certification::factory()->published()->create(['name' => '資格テスト名']);

        $third = $this->createMockExam($certification, $updater, '模試C', 3);
        $first = $this->createMockExam($certification, $updater, '模試A', 1);
        $second = $this->createMockExam($certification, $updater, '模試B', 2);

        MockExamQuestion::factory()->count(2)->create(['mock_exam_id' => $first->id]);
        MockExamQuestion::factory()->count(5)->create(['mock_exam_id' => $second->id]);

        // Act
        $response = $this->actingAs($admin)->get(route('admin.mock-exams.index'));

        // Assert: 並びは certification_id → order。各行の関連情報が表示用データに載っている
        $response->assertOk();
        $response->assertSeeInOrder(['模試A', '模試B', '模試C']);

        /** @var LengthAwarePaginator $mockExams */
        $mockExams = $response->viewData('mockExams');
        $rows = $mockExams->getCollection()->keyBy('id');

        $this->assertSame(['模試A', '模試B', '模試C'], $mockExams->pluck('title')->all());
        $this->assertSame(2, $rows[$first->id]->mock_exam_questions_count);
        $this->assertSame(5, $rows[$second->id]->mock_exam_questions_count);
        $this->assertSame(0, $rows[$third->id]->mock_exam_questions_count);
        $this->assertSame('資格テスト名', $rows[$first->id]->certification->name);
        $this->assertSame('更新者テスト', $rows[$first->id]->updatedBy->name);
        $response->assertSee('資格テスト名');
        $response->assertSee('更新者テスト');
    }

    private function createMockExam(Certification $certification, User $updater, string $title, int $order): MockExam
    {
        return MockExam::factory()
            ->forCertification($certification)
            ->create([
                'title' => $title,
                'order' => $order,
                'updated_by_user_id' => $updater->id,
            ]);
    }
}
