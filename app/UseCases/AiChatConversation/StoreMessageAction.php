<?php

declare(strict_types=1);

namespace App\UseCases\AiChatConversation;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\AiChatLlmFailedException;
use App\Exceptions\AiChat\AiChatUnavailableException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Services\AiChat\AiChatDailyLimitService;
use App\Services\AiChat\GeminiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 質問を送信し、Gemini の応答を保存するユースケース。
 *
 * 順序は「API キー確認 → 日次上限の加算 → 質問の保存 → AI 呼び出し」。
 * API キー未設定や上限超過では何も保存しない。AI 呼び出しが失敗しても質問は残し、
 * エラー状態の応答メッセージを保存してから例外を投げる。
 */
final class StoreMessageAction
{
    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly AiChatDailyLimitService $dailyLimit,
    ) {}

    /**
     * @throws AiChatUnavailableException
     * @throws AiChatDailyLimitExceededException
     * @throws AiChatLlmFailedException
     */
    public function __invoke(AiChatConversation $conversation, string $content): AiChatMessage
    {
        if (! $this->gemini->isConfigured()) {
            throw new AiChatUnavailableException;
        }

        $this->dailyLimit->consume($conversation->user);

        $isFirstExchange = ! $conversation->messages()
            ->where('role', AiChatMessageRole::Assistant->value)
            ->where('status', AiChatMessageStatus::Completed->value)
            ->exists();

        $history = $this->history($conversation);

        $this->saveMessage($conversation, AiChatMessageRole::User, AiChatMessageStatus::Completed, $content);

        $history[] = ['role' => 'user', 'text' => $content];

        try {
            $result = $this->gemini->generate((string) config('ai-chat.system_prompt'), $history);
        } catch (AiChatLlmFailedException $e) {
            $this->saveMessage(
                $conversation,
                AiChatMessageRole::Assistant,
                AiChatMessageStatus::Error,
                '',
                $e->detail,
            );

            Log::channel('ai-chat')->warning('ai_chat.llm_failed', [
                'conversation_id' => $conversation->id,
                'upstream_status' => $e->upstreamStatus,
                'detail' => $e->detail,
            ]);

            throw $e;
        }

        $reply = $this->saveMessage(
            $conversation,
            AiChatMessageRole::Assistant,
            AiChatMessageStatus::Completed,
            $result['text'],
            null,
            $result,
        );

        Log::channel('ai-chat')->info('ai_chat.message_completed', [
            'conversation_id' => $conversation->id,
            'model' => $result['model'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'response_time_ms' => $result['response_time_ms'],
        ]);

        if ($isFirstExchange && config('ai-chat.auto_title')) {
            $this->retitle($conversation, $content, $result['text']);
        }

        return $reply;
    }

    /**
     * @return array<int, array{role: 'user'|'model', text: string}>
     */
    private function history(AiChatConversation $conversation): array
    {
        $limit = (int) config('ai-chat.history_limit');

        return $conversation->messages()
            ->where('status', AiChatMessageStatus::Completed->value)
            ->reorder()
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (AiChatMessage $m) => [
                'role' => $m->role === AiChatMessageRole::User ? 'user' : 'model',
                'text' => $m->content,
            ])
            ->all();
    }

    /**
     * @param array{input_tokens: ?int, output_tokens: ?int, response_time_ms: int, model: string}|null $meta
     */
    private function saveMessage(
        AiChatConversation $conversation,
        AiChatMessageRole $role,
        AiChatMessageStatus $status,
        string $content,
        ?string $errorDetail = null,
        ?array $meta = null,
    ): AiChatMessage {
        return DB::transaction(function () use ($conversation, $role, $status, $content, $errorDetail, $meta) {
            $message = $conversation->messages()->create([
                'role' => $role->value,
                'status' => $status->value,
                'content' => $content,
                'error_detail' => $errorDetail,
                'model' => $meta['model'] ?? null,
                'input_tokens' => $meta['input_tokens'] ?? null,
                'output_tokens' => $meta['output_tokens'] ?? null,
                'response_time_ms' => $meta['response_time_ms'] ?? null,
            ]);

            $conversation->update(['last_message_at' => now()]);

            return $message;
        });
    }

    /**
     * 最初の AI 応答後に会話タイトルを付け直す。失敗しても応答自体は成功とするため例外は握りつぶす。
     */
    private function retitle(AiChatConversation $conversation, string $question, string $answer): void
    {
        try {
            $result = $this->gemini->generate(
                '以下の会話の内容を表す日本語のタイトルを、30文字以内で1行だけ出力してください。記号や引用符は付けないでください。',
                [['role' => 'user', 'text' => "質問: {$question}\n回答: {$answer}"]],
            );

            $title = trim(str_replace(["\r", "\n", '「', '」', '"'], '', $result['text']));

            if ($title !== '') {
                $conversation->update(['title' => mb_substr($title, 0, 100)]);
            }
        } catch (Throwable $e) {
            Log::channel('ai-chat')->warning('ai_chat.retitle_failed', [
                'conversation_id' => $conversation->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
