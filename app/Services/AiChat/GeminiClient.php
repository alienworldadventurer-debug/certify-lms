<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use App\Exceptions\AiChat\AiChatLlmFailedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gemini API（generateContent）を同期で呼び出すクライアント。
 * 接続失敗・429・5xx の一時的な失敗のみ再試行し、空応答などは再試行しない。
 */
class GeminiClient
{
    private const RETRY_TIMES = 3;

    private const RETRY_SLEEP_MS = 500;

    public function isConfigured(): bool
    {
        return filled(config('ai-chat.gemini.api_key'));
    }

    /**
     * @param array<int, array{role: 'user'|'model', text: string}> $contents
     *
     * @return array{text: string, model: string, input_tokens: ?int, output_tokens: ?int, response_time_ms: int}
     *
     * @throws AiChatLlmFailedException
     */
    public function generate(string $systemPrompt, array $contents): array
    {
        $model = (string) config('ai-chat.gemini.model');
        $startedAt = hrtime(true);

        try {
            $response = Http::timeout((int) config('ai-chat.gemini.timeout'))
                ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, $this->isTransientFailure(...), throw: false)
                ->withHeaders(['x-goog-api-key' => (string) config('ai-chat.gemini.api_key')])
                ->post($this->endpoint($model), [
                    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents' => array_map(fn (array $c) => [
                        'role' => $c['role'],
                        'parts' => [['text' => $c['text']]],
                    ], $contents),
                ]);
        } catch (ConnectionException $e) {
            throw new AiChatLlmFailedException(null, 'Gemini API に接続できませんでした: '.$e->getMessage(), $e);
        }

        if (! $response->successful()) {
            throw new AiChatLlmFailedException(
                $response->status(),
                'Gemini API がエラーを返しました: HTTP '.$response->status(),
            );
        }

        $text = (string) $response->json('candidates.0.content.parts.0.text', '');

        if ($text === '') {
            throw new AiChatLlmFailedException($response->status(), 'Gemini API の応答が空でした。');
        }

        return [
            'text' => $text,
            'model' => $model,
            'input_tokens' => $response->json('usageMetadata.promptTokenCount'),
            'output_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
            'response_time_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }

    private function isTransientFailure(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->status() === 429 || $e->response->serverError());
    }

    private function endpoint(string $model): string
    {
        return rtrim((string) config('ai-chat.gemini.base_url'), '/')."/models/{$model}:generateContent";
    }
}
