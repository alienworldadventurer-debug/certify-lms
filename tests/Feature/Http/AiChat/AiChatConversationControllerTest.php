<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI 相談の HTTP 層（ルート・ミドルウェア・権限・バリデーション・JSON/HTML 応答）の検証。
 *
 * Gemini API は Http::fake で置き換え、外部通信は行わない。
 */
class AiChatConversationControllerTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI_URL = 'generativelanguage.googleapis.com/*';

    /**
     * テストごとに Gemini の応答を差し替える。null のときは成功応答を返す。
     */
    private mixed $geminiResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai-chat.enabled' => true,
            'ai-chat.auto_title' => false,
            'ai-chat.daily_limit' => 50,
            'ai-chat.gemini.api_key' => 'test-key',
        ]);

        Http::fake([
            self::GEMINI_URL => fn () => $this->geminiResponse ?? Http::response($this->geminiBody('回答です')),
        ]);
    }

    public function test_feature_switch_off_returns_404(): void
    {
        config(['ai-chat.enabled' => false]);
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->getJson('/ai-chat')
            ->assertNotFound();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/ai-chat/conversations', ['message' => 'こんにちは'])
            ->assertUnauthorized();
    }

    public function test_non_student_is_forbidden(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $this->actingAs($coach)
            ->postJson('/ai-chat/conversations', ['message' => 'こんにちは'])
            ->assertForbidden();
    }

    public function test_index_shows_empty_state_when_no_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->get('/ai-chat')
            ->assertOk()
            ->assertViewIs('ai-chat.empty-state');
    }

    public function test_index_redirects_to_latest_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        AiChatConversation::factory()->create(['user_id' => $student->id, 'last_message_at' => now()->subDay()]);
        $latest = AiChatConversation::factory()->create(['user_id' => $student->id, 'last_message_at' => now()]);

        $this->actingAs($student)
            ->get('/ai-chat')
            ->assertRedirect(route('ai-chat.conversations.show', $latest));
    }

    public function test_store_json_creates_conversation_and_answers_with_201(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['message' => 'ベクトルとは何ですか'])
            ->assertCreated()
            ->assertJsonPath('conversation.title', 'ベクトルとは何ですか')
            ->assertJsonPath('conversation.section_id', null);

        $conversation = AiChatConversation::query()->where('user_id', $student->id)->sole();
        $this->assertSame(['user', 'assistant'], $conversation->messages()->orderBy('created_at')->get()
            ->map(fn (AiChatMessage $m) => $m->role->value)->all());
        $this->assertSame('回答です', $conversation->messages()->where('role', 'assistant')->value('content'));
    }

    public function test_store_json_without_message_creates_empty_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', [])
            ->assertCreated()
            ->assertJsonPath('conversation.title', '新しい相談');

        Http::assertNothingSent();
        $this->assertSame(0, AiChatMessage::query()->count());
    }

    public function test_store_json_with_section_links_enrollment_and_reuses_existing_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $section = Section::factory()->published()->create();
        $enrollment = Enrollment::factory()->learning()->create([
            'user_id' => $student->id,
            'certification_id' => $section->chapter->part->certification_id,
        ]);

        $first = $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['section_id' => $section->id, 'message' => '質問1'])
            ->assertCreated()
            ->assertJsonPath('conversation.enrollment_id', $enrollment->id)
            ->json('conversation.id');

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['section_id' => $section->id, 'message' => '質問2'])
            ->assertOk()
            ->assertJsonPath('conversation.id', $first);

        $this->assertSame(1, AiChatConversation::query()->count());
    }

    public function test_store_json_rejects_invalid_source_without_saving(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['message' => '質問', 'source' => 'unknown'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source']);

        Http::assertNothingSent();
        $this->assertSame(0, AiChatConversation::query()->count());
    }

    public function test_store_json_accepts_known_source(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['message' => '質問', 'source' => 'widget'])
            ->assertCreated();
    }

    public function test_store_json_rejects_message_longer_than_2000_characters(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['message' => str_repeat('あ', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);

        $this->assertSame(0, AiChatConversation::query()->count());
    }

    public function test_store_json_returns_500_and_saves_nothing_when_api_key_is_missing(): void
    {
        config(['ai-chat.gemini.api_key' => null]);
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['message' => '質問'])
            ->assertStatus(500);

        Http::assertNothingSent();
        $this->assertSame(0, AiChatConversation::query()->count());
    }

    public function test_store_json_returns_429_and_saves_nothing_when_daily_limit_is_reached(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        AiChatDailyUsage::query()->create([
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')->toDateString(),
            'count' => 50,
        ]);

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['message' => '質問'])
            ->assertStatus(429);

        Http::assertNothingSent();
        $this->assertSame(0, AiChatConversation::query()->count());
    }

    public function test_store_json_returns_502_with_upstream_status_and_keeps_question(): void
    {
        $this->geminiResponse = Http::response(['error' => 'busy'], 503);
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['message' => '質問'])
            ->assertStatus(502)
            ->assertJsonPath('upstream_status', 503);

        $conversation = AiChatConversation::query()->where('user_id', $student->id)->sole();
        $this->assertSame(
            ['user', 'assistant'],
            $conversation->messages()->orderBy('created_at')->get()->map(fn (AiChatMessage $m) => $m->role->value)->all(),
        );
        $this->assertSame(AiChatMessageStatus::Error, $conversation->messages()->where('role', 'assistant')->first()->status);
    }

    public function test_store_html_redirects_to_conversation_with_error_on_upstream_failure(): void
    {
        $this->geminiResponse = Http::response([], 500);
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->post('/ai-chat/conversations', ['message' => '質問'])
            ->assertRedirect(route('ai-chat.conversations.show', AiChatConversation::query()->where('user_id', $student->id)->sole()))
            ->assertSessionHas('error');
    }

    public function test_store_html_redirects_to_conversation_on_success(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->post('/ai-chat/conversations', ['message' => '質問'])
            ->assertRedirect();

        $this->assertSame(1, AiChatConversation::query()->where('user_id', $student->id)->count());
    }

    public function test_show_returns_messages_without_internal_fields_for_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $conversation->messages()->create(['role' => 'user', 'status' => 'completed', 'content' => '質問']);

        $this->actingAs($student)
            ->getJson(route('ai-chat.conversations.show', $conversation))
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonCount(1, 'messages')
            ->assertJsonMissingPath('messages.0.model')
            ->assertJsonMissingPath('messages.0.input_tokens');
    }

    public function test_show_is_forbidden_for_another_student(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson(route('ai-chat.conversations.show', $conversation))
            ->assertForbidden();
    }

    public function test_store_message_json_returns_both_messages_and_increments_usage(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '続きの質問'])
            ->assertOk()
            ->assertJsonPath('user_message.role', 'user')
            ->assertJsonPath('user_message.content', '続きの質問')
            ->assertJsonPath('assistant_message.role', 'assistant')
            ->assertJsonPath('assistant_message.content', '回答です');

        $this->assertSame(1, AiChatDailyUsage::query()->where('user_id', $student->id)->value('count'));
    }

    public function test_store_message_rejects_blank_content(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '  '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);

        Http::assertNothingSent();
    }

    public function test_store_message_returns_429_when_daily_limit_is_reached(): void
    {
        config(['ai-chat.daily_limit' => 1]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatDailyUsage::query()->create([
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')->toDateString(),
            'count' => 1,
        ]);

        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '質問'])
            ->assertStatus(429);

        Http::assertNothingSent();
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_store_message_html_redirects_with_error_on_upstream_failure(): void
    {
        $this->geminiResponse = Http::response([], 500);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)
            ->post(route('ai-chat.conversations.messages.store', $conversation), ['content' => '質問'])
            ->assertRedirect(route('ai-chat.conversations.show', $conversation))
            ->assertSessionHas('error');
    }

    public function test_update_changes_title_for_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id, 'title' => '旧']);

        $this->actingAs($student)
            ->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => '新しいタイトル'])
            ->assertOk()
            ->assertJsonPath('conversation.title', '新しいタイトル');

        $this->assertSame('新しいタイトル', $conversation->refresh()->title);
    }

    public function test_update_rejects_blank_title(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id, 'title' => '旧']);

        $this->actingAs($student)
            ->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => ' '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);

        $this->assertSame('旧', $conversation->refresh()->title);
    }

    public function test_update_is_forbidden_for_another_student(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id, 'title' => '旧']);

        $this->actingAs($other)
            ->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => '乗っ取り'])
            ->assertForbidden();

        $this->assertSame('旧', $conversation->refresh()->title);
    }

    public function test_destroy_deletes_conversation_for_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)
            ->deleteJson(route('ai-chat.conversations.destroy', $conversation))
            ->assertNoContent();

        $this->assertNull(AiChatConversation::query()->find($conversation->id));
    }

    public function test_destroy_is_forbidden_for_another_student(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->deleteJson(route('ai-chat.conversations.destroy', $conversation))
            ->assertForbidden();

        $this->assertNotNull(AiChatConversation::query()->find($conversation->id));
    }

    public function test_store_json_accepts_message_of_exactly_2000_characters(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['message' => str_repeat('あ', 2000)])
            ->assertCreated();

        $this->assertSame(2000, mb_strlen(AiChatMessage::query()->where('role', 'user')->value('content')));
    }

    public function test_store_json_rejects_message_of_2001_characters_with_exact_message(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['message' => str_repeat('あ', 2001)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.message.0', '質問内容は2,000文字以内で入力してください。');
    }

    public function test_store_json_includes_section_title_in_gemini_system_instruction(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $section = Section::factory()->published()->create(['title' => 'テスト用教材タイトル']);
        Enrollment::factory()->learning()->create([
            'user_id' => $student->id,
            'certification_id' => $section->chapter->part->certification_id,
        ]);

        $this->actingAs($student)
            ->postJson('/ai-chat/conversations', ['section_id' => $section->id, 'message' => '質問'])
            ->assertCreated();

        Http::assertSent(fn ($request) => str_contains(
            json_encode($request->data(), JSON_UNESCAPED_UNICODE),
            '受講生が閲覧中の教材: テスト用教材タイトル',
        ));
    }

    public function test_store_message_is_forbidden_for_another_student_and_saves_nothing(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), ['message' => '割り込み'])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, AiChatMessage::query()->count());
    }

    public function test_store_message_is_forbidden_for_admin(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), ['message' => '質問'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_guest_html_request_is_redirected_to_login(): void
    {
        $this->get('/ai-chat')->assertRedirect(route('login'));
    }

    public function test_show_returns_404_for_missing_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->getJson('/ai-chat/conversations/01HZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertNotFound();
    }

    public function test_update_html_redirects_with_success_flash(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id, 'title' => '旧']);

        $this->actingAs($student)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => '新'])
            ->assertRedirect(route('ai-chat.conversations.show', $conversation))
            ->assertSessionHas('success', '会話タイトルを更新しました。');
    }

    public function test_destroy_html_redirects_to_index_and_cascades_messages(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'status' => 'completed',
            'content' => '質問',
        ]);

        $this->actingAs($student)
            ->delete(route('ai-chat.conversations.destroy', $conversation))
            ->assertRedirect(route('ai-chat.index'))
            ->assertSessionHas('success', '会話を削除しました。');

        $this->assertSame(0, AiChatMessage::query()->count());
    }

    public function test_title_of_101_characters_is_rejected_with_exact_message(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)
            ->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => str_repeat('あ', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiBody(string $text): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => $text]]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ];
    }
}
