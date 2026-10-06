<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_question_lifecycle_preserves_certification_and_required_messages(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id, 'title' => '  title  ', 'body' => '  body  ',
        ])->assertSessionHas('success', '質問を投稿しました。')->assertRedirect();
        $thread = QaThread::query()->firstOrFail();
        $this->assertSame('title', $thread->title);
        $this->assertSame('body', $thread->body);
        $this->get(route('qa-board.edit', $thread))->assertOk();
        $this->post(route('qa-board.resolve', $thread))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '質問を解決済みにしました。');
        $this->assertSame(QaThreadStatus::Resolved, $thread->fresh()->status);
        $this->assertNotNull($thread->fresh()->resolved_at);
        $this->patch(route('qa-board.update', $thread), ['title' => 'new', 'body' => 'new body'])
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '質問を更新しました。');
        $this->assertSame($certification->id, $thread->fresh()->certification_id);
        $this->post(route('qa-board.unresolve', $thread))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '質問を未解決に戻しました。');
        $this->assertSame(QaThreadStatus::Open, $thread->fresh()->status);
        $this->assertNull($thread->fresh()->resolved_at);
        $this->delete(route('qa-board.destroy', $thread))
            ->assertRedirect(route('qa-board.index'))
            ->assertSessionHas('success', '質問を削除しました。');
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
    }

    public function test_replies_can_be_posted_on_resolved_questions_and_redirect_to_anchors(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->resolved()->for($student, 'user')->create();

        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), ['body' => '  answer  ']);
        $reply = QaReply::query()->firstOrFail();
        $response->assertRedirect(route('qa-board.show', $thread).'#reply-'.$reply->id)
            ->assertSessionHas('success', '回答を投稿しました。');
        $this->assertSame('answer', $reply->body);
        $this->patch(route('qa-board.update', $thread), ['title' => 'edited after reply', 'body' => 'edited'])
            ->assertSessionHas('success', '質問を更新しました。');
        $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => 'new answer'])
            ->assertRedirect(route('qa-board.show', $thread).'#reply-'.$reply->id)
            ->assertSessionHas('success', '回答を更新しました。');
        $this->delete(route('qa-board.replies.destroy', [$thread, $reply]))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '回答を削除しました。');
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }

    public function test_reply_conflict_preserves_data_for_both_question_states(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for($student, 'user')->create();
        $reply = QaReply::factory()->for($thread, 'thread')->create();

        foreach ([QaThreadStatus::Open, QaThreadStatus::Resolved] as $status) {
            $thread->update(['status' => $status]);
            $this->actingAs($student)->deleteJson(route('qa-board.destroy', $thread))->assertStatus(409);
            $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
            $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
        }
        $this->from(route('qa-board.show', $thread))->delete(route('qa-board.destroy', $thread))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('error', '回答が付いている質問は削除できません。');
    }

    public function test_admin_moderation_keeps_parent_when_deleting_reply_and_cascades_question_deletion(): void
    {
        $thread = QaThread::factory()->for(Certification::factory()->archived())->create();
        $reply = QaReply::factory()->for($thread, 'thread')->create();
        $otherReply = QaReply::factory()->for($thread, 'thread')->create();

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.qa-board.show', $thread))->assertOk();
        $this->delete(route('admin.qa-board.replies.destroy', [$thread, $reply]))
            ->assertRedirect(route('admin.qa-board.show', $thread))
            ->assertSessionHas('success', '回答を削除しました。');
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $otherReply->id]);
        $this->delete(route('admin.qa-board.destroy', $thread))
            ->assertRedirect(route('admin.qa-board.index'))
            ->assertSessionHas('success', '質問を削除しました。');
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseMissing('qa_replies', ['qa_thread_id' => $thread->id]);
    }

    public function test_retired_authors_are_unknown_and_body_is_escaped_with_line_breaks(): void
    {
        $author = User::factory()->student()->create(['name' => 'HiddenAuthorName']);
        $thread = QaThread::factory()->for($author, 'user')->create(['body' => "<script>alert(1)</script>\nnext"]);
        $reply = QaReply::factory()->for($author, 'user')->for($thread, 'thread')->create(['body' => '<b>reply</b>']);
        $author->update(['status' => 'withdrawn']);

        $this->actingAs(User::factory()->student()->create())->get(route('qa-board.show', $thread))
            ->assertOk()->assertSee('不明')->assertDontSee('HiddenAuthorName')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;<br />', false)
            ->assertSee('&lt;b&gt;reply&lt;/b&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    public function test_resolved_question_without_replies_can_be_deleted_by_its_author(): void
    {
        $thread = QaThread::factory()->resolved()->create();

        $this->actingAs($thread->user)->delete(route('qa-board.destroy', $thread))
            ->assertRedirect(route('qa-board.index'))
            ->assertSessionHas('success', '質問を削除しました。');
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
    }

    public function test_coach_can_post_update_and_delete_own_reply_to_a_resolved_question(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->resolved()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $thread->certification_id, 'user_id' => $coach->id,
        ]);

        $response = $this->actingAs($coach)->post(route('qa-board.replies.store', $thread), ['body' => 'coach answer']);
        $reply = QaReply::query()->firstOrFail();
        $response->assertRedirect(route('qa-board.show', $thread).'#reply-'.$reply->id)
            ->assertSessionHas('success', '回答を投稿しました。');
        $this->get(route('qa-board.replies.edit', [$thread, $reply]))->assertOk();
        $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => 'coach update'])
            ->assertRedirect(route('qa-board.show', $thread).'#reply-'.$reply->id)
            ->assertSessionHas('success', '回答を更新しました。');
        $this->delete(route('qa-board.replies.destroy', [$thread, $reply]))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '回答を削除しました。');
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }
}
