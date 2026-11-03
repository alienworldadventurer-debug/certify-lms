<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SectionQuestion;

use App\Models\Certification;
use App\Models\SectionQuestion;
use App\Models\SectionQuestionOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_create_section_question_with_options(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);

        $this->actingAs($admin)
            ->post(route('admin.sections.questions.store', $section), [
                'body' => 'Markdown を安全に描画するために最も適切な処理はどれか?',
                'explanation' => 'XSS 対策として html_input=strip が有効。',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'プレーンテキストとして表示する', 'is_correct' => false, 'order' => 0],
                    ['body' => 'CommonMark で html_input=strip を有効化する', 'is_correct' => true, 'order' => 1],
                    ['body' => '何もせずそのまま表示する', 'is_correct' => false, 'order' => 2],
                    ['body' => 'iframe で囲む', 'is_correct' => false, 'order' => 3],
                ],
            ])
            ->assertRedirect();

        $question = SectionQuestion::firstOrFail();
        $this->assertSame('draft', $question->status->value);
        $this->assertSame($section->id, $question->section_id);
        $this->assertSame(4, $question->options()->count());
        $this->assertSame(1, $question->options()->where('is_correct', true)->count());
    }

    public function test_store_rejects_zero_correct_options(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);

        $this->actingAs($admin)
            ->postJson(route('admin.sections.questions.store', $section), [
                'body' => 'Q?',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'A', 'is_correct' => false, 'order' => 0],
                    ['body' => 'B', 'is_correct' => false, 'order' => 1],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_category_from_different_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $otherCert = Certification::factory()->published()->create();
        $foreignCategory = $this->makeCategory($otherCert);

        $this->actingAs($admin)
            ->postJson(route('admin.sections.questions.store', $section), [
                'body' => 'Q?',
                'category_id' => $foreignCategory->id,
                'options' => [
                    ['body' => 'A', 'is_correct' => true, 'order' => 0],
                    ['body' => 'B', 'is_correct' => false, 'order' => 1],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_single_option(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);

        $this->actingAs($admin)
            ->postJson(route('admin.sections.questions.store', $section), [
                'body' => 'Q?',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'A', 'is_correct' => true, 'order' => 0],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_update_replaces_options_via_delete_and_insert(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);
        $question = SectionQuestion::factory()
            ->forSection($section)
            ->forCategory($category)
            ->withOptions(4)
            ->draft()
            ->create();

        $originalOptionIds = $question->options()->pluck('id')->all();

        $this->actingAs($admin)
            ->patch(route('admin.section-questions.update', $question), [
                'body' => 'updated',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'new A', 'is_correct' => true, 'order' => 0],
                    ['body' => 'new B', 'is_correct' => false, 'order' => 1],
                    ['body' => 'new C', 'is_correct' => false, 'order' => 2],
                ],
            ])
            ->assertRedirect();

        $question->refresh();
        $this->assertSame(3, $question->options()->count());
        $this->assertSame(0, SectionQuestionOption::whereIn('id', $originalOptionIds)->count());
    }

    public function test_publish_requires_valid_options(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);

        $bad = SectionQuestion::factory()->forSection($section)->forCategory($category)->draft()->create();
        $bad->options()->create(['body' => 'lone', 'is_correct' => true, 'order' => 0]);

        $this->actingAs($admin)
            ->postJson(route('admin.section-questions.publish', $bad))
            ->assertStatus(409);

        $good = SectionQuestion::factory()->forSection($section)->forCategory($category)->withOptions(4)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.section-questions.publish', $good))
            ->assertRedirect();
        $this->assertSame('published', $good->fresh()->status->value);
    }

    public function test_assigned_coach_can_manage_section_questions(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert);
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);

        // 一覧表示
        $this->actingAs($coach)
            ->get(route('admin.sections.questions.index', $section))
            ->assertOk()
            ->assertViewIs('section-question.management.index');

        // 作成画面表示
        $this->actingAs($coach)
            ->get(route('admin.sections.questions.create', $section))
            ->assertOk()
            ->assertViewIs('section-question.management.create');

        // 作成
        $this->actingAs($coach)
            ->post(route('admin.sections.questions.store', $section), [
                'body' => 'コーチ作成の問題文',
                'explanation' => '解説',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'A', 'is_correct' => true, 'order' => 0],
                    ['body' => 'B', 'is_correct' => false, 'order' => 1],
                ],
            ])
            ->assertRedirect();
        $question = SectionQuestion::where('body', 'コーチ作成の問題文')->firstOrFail();

        // 詳細表示
        $this->actingAs($coach)
            ->get(route('admin.section-questions.show', $question))
            ->assertOk()
            ->assertViewIs('section-question.management.show');

        // 更新
        $this->actingAs($coach)
            ->patch(route('admin.section-questions.update', $question), [
                'body' => '更新後の問題文',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'A更新', 'is_correct' => true, 'order' => 0],
                    ['body' => 'B更新', 'is_correct' => false, 'order' => 1],
                ],
            ])
            ->assertRedirect(route('admin.section-questions.show', $question));
        $this->assertSame('更新後の問題文', $question->fresh()->body);

        // 公開
        $this->actingAs($coach)
            ->post(route('admin.section-questions.publish', $question))
            ->assertRedirect(route('admin.section-questions.show', $question));
        $this->assertSame('published', $question->fresh()->status->value);

        // 下書き化
        $this->actingAs($coach)
            ->post(route('admin.section-questions.unpublish', $question))
            ->assertRedirect(route('admin.section-questions.show', $question));
        $this->assertSame('draft', $question->fresh()->status->value);

        // 削除
        $this->actingAs($coach)
            ->delete(route('admin.section-questions.destroy', $question))
            ->assertRedirect(route('admin.sections.questions.index', $section));
        $this->assertDatabaseMissing('section_questions', ['id' => $question->id]);
    }

    public function test_non_assigned_coach_cannot_view_section_questions(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);
        $question = SectionQuestion::factory()
            ->forSection($section)
            ->forCategory($category)
            ->withOptions(2)
            ->draft()
            ->create();

        // 一覧
        $this->actingAs($coach)
            ->get(route('admin.sections.questions.index', $section))
            ->assertForbidden();

        // 作成画面
        $this->actingAs($coach)
            ->get(route('admin.sections.questions.create', $section))
            ->assertForbidden();

        // 作成
        $this->actingAs($coach)
            ->post(route('admin.sections.questions.store', $section), [
                'body' => '拒否',
                'category_id' => $category->id,
                'options' => [
                    ['body' => 'A', 'is_correct' => true, 'order' => 0],
                    ['body' => 'B', 'is_correct' => false, 'order' => 1],
                ],
            ])
            ->assertForbidden();

        // 詳細
        $this->actingAs($coach)
            ->get(route('admin.section-questions.show', $question))
            ->assertForbidden();

        // 更新
        $this->actingAs($coach)
            ->patch(route('admin.section-questions.update', $question), [
                'body' => '拒否',
                'category_id' => $category->id,
            ])
            ->assertForbidden();

        // 公開
        $this->actingAs($coach)
            ->post(route('admin.section-questions.publish', $question))
            ->assertForbidden();

        // 下書き化拒否
        $publishedQuestion = SectionQuestion::factory()
            ->forSection($section)
            ->forCategory($category)
            ->withOptions(2)
            ->published()
            ->create();
        $this->actingAs($coach)
            ->post(route('admin.section-questions.unpublish', $publishedQuestion))
            ->assertForbidden();

        // 削除
        $this->actingAs($coach)
            ->delete(route('admin.section-questions.destroy', $question))
            ->assertForbidden();
    }

    public function test_destroy_soft_deletes_section_question(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = $this->makeCategory($cert);
        $question = SectionQuestion::factory()
            ->forSection($section)
            ->forCategory($category)
            ->draft()
            ->create();

        $this->actingAs($admin)
            ->delete(route('admin.section-questions.destroy', $question))
            ->assertRedirect();

        $this->assertDatabaseMissing('section_questions', ['id' => $question->id]);
    }
}
