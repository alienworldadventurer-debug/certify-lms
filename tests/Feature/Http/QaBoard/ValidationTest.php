<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaThread\StoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_question_post_and_update_accept_exact_japanese_limits(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $input = ['title' => str_repeat('題', 200), 'body' => str_repeat('文', 5000)];

        $this->actingAs($student)->post(route('qa-board.store'), [
            ...$input, 'certification_id' => $certification->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $thread = QaThread::query()->firstOrFail();
        $this->assertSame($input['body'], $thread->body);
        $this->patch(route('qa-board.update', $thread), $input)->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_required_and_over_limit_question_errors_preserve_input_without_saving(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for($student, 'user')->create();
        $this->actingAs($student);

        foreach ([
            [['title' => '   ', 'body' => "\t\n "], ['title' => 'タイトルは必須です。', 'body' => '質問本文は必須です。']],
            [['title' => str_repeat('題', 201), 'body' => str_repeat('文', 5001)], [
                'title' => 'タイトルは200文字以内で入力してください。',
                'body' => '質問本文は5,000文字以内で入力してください。',
            ]],
        ] as [$input, $messages]) {
            $this->from(route('qa-board.create'))->post(route('qa-board.store'), [
                ...$input, 'certification_id' => $thread->certification_id,
            ])->assertRedirect(route('qa-board.create'))->assertSessionHasErrors($messages);
            $this->assertDatabaseCount('qa_threads', 1);
            $this->patch(route('qa-board.update', $thread), $input)->assertSessionHasErrors($messages);
            $this->assertSame($thread->title, $thread->fresh()->title);
            $this->assertSame($thread->body, $thread->fresh()->body);
        }
        $this->assertSame(str_repeat('題', 201), session()->getOldInput('title'));
    }

    public function test_question_requires_an_existing_published_ulid_and_rejects_certification_updates(): void
    {
        $student = User::factory()->student()->create();
        $draft = Certification::factory()->draft()->create();
        $archived = Certification::factory()->archived()->create();
        $thread = QaThread::factory()->for($student, 'user')->create();
        $this->actingAs($student);

        foreach ([null, '', '   ', 'invalid', 123, ['id'], (string) Str::ulid(), $draft->id, $archived->id] as $id) {
            $message = in_array($id, [null, '', '   '], true)
                ? '資格は必須です。' : '選択した資格は選択できません。';
            $this->post(route('qa-board.store'), ['certification_id' => $id, 'title' => 'title', 'body' => 'body'])
                ->assertSessionHasErrors(['certification_id' => $message]);
        }
        $this->patch(route('qa-board.update', $thread), [
            'certification_id' => Certification::factory()->published()->create()->id,
            'title' => 'changed', 'body' => 'changed',
        ])->assertSessionHasErrors('certification_id');
        $this->assertSame($thread->certification_id, $thread->fresh()->certification_id);
        $this->assertDatabaseCount('qa_threads', 1);
    }

    public function test_reply_post_and_update_validate_required_and_5000_character_limit(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->create();

        $this->actingAs($student)->post(route('qa-board.replies.store', $thread), ['body' => str_repeat('答', 5000)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $reply = QaReply::query()->firstOrFail();
        $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => str_repeat('答', 5000)])
            ->assertSessionHasNoErrors()->assertRedirect();
        foreach ([
            '   ' => '回答は必須です。',
            str_repeat('答', 5001) => '回答は5,000文字以内で入力してください。',
        ] as $body => $message) {
            $this->post(route('qa-board.replies.store', $thread), ['body' => $body])
                ->assertSessionHasErrors(['body' => $message]);
            $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => $body])
                ->assertSessionHasErrors(['body' => $message]);
        }
        $this->assertDatabaseCount('qa_replies', 1);
        $this->assertSame(str_repeat('答', 5000), $reply->fresh()->body);
    }

    public function test_store_action_rechecks_publication_after_request_validation(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $validated = ['certification_id' => $certification->id, 'title' => 'title', 'body' => 'body'];
        $certification->update(['status' => 'archived']);

        try {
            app(StoreAction::class)($student, $validated);
            $this->fail('An unpublished certification must not accept questions.');
        } catch (ValidationException $exception) {
            $this->assertSame(['選択した資格は選択できません。'], $exception->errors()['certification_id']);
        }
        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_title_and_bodies_reject_array_input(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for($student, 'user')->create();

        $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $thread->certification_id, 'title' => ['title'], 'body' => ['body'],
        ])->assertSessionHasErrors(['title', 'body']);
        $this->post(route('qa-board.replies.store', $thread), ['body' => ['reply']])->assertSessionHasErrors('body');
        $this->assertDatabaseCount('qa_threads', 1);
        $this->assertDatabaseCount('qa_replies', 0);
    }
}
