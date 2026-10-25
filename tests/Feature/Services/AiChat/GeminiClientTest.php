<?php

declare(strict_types=1);

namespace Tests\Feature\Services\AiChat;

use App\Exceptions\AiChat\AiChatLlmFailedException;
use App\Services\AiChat\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GeminiClient の応答解析と失敗時の例外変換を検証する。外部通信は Http::fake で置き換える。
 */
class GeminiClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai-chat.gemini.api_key' => 'test-key',
            'ai-chat.gemini.model' => 'gemini-2.5-flash',
        ]);
    }

    public function test_is_configured_only_when_api_key_is_present(): void
    {
        $client = app(GeminiClient::class);

        $this->assertTrue($client->isConfigured());

        config(['ai-chat.gemini.api_key' => null]);
        $this->assertFalse($client->isConfigured());
    }

    public function test_returns_text_and_token_usage_from_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'こんにちは']]]]],
                'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 7],
            ]),
        ]);

        $result = app(GeminiClient::class)->generate('system', [['role' => 'user', 'text' => '質問']]);

        $this->assertSame('こんにちは', $result['text']);
        $this->assertSame('gemini-2.5-flash', $result['model']);
        $this->assertSame(12, $result['input_tokens']);
        $this->assertSame(7, $result['output_tokens']);
        $this->assertIsInt($result['response_time_ms']);
    }

    public function test_sends_system_instruction_and_history_with_api_key_header(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '回答']]]]],
            ]),
        ]);

        app(GeminiClient::class)->generate('システム指示', [
            ['role' => 'user', 'text' => '1つ目'],
            ['role' => 'model', 'text' => '返答'],
            ['role' => 'user', 'text' => '2つ目'],
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'models/gemini-2.5-flash:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && $body['systemInstruction']['parts'][0]['text'] === 'システム指示'
                && count($body['contents']) === 3
                && $body['contents'][1]['role'] === 'model';
        });
    }

    public function test_throws_with_upstream_status_on_http_error(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 503)]);

        try {
            app(GeminiClient::class)->generate('system', [['role' => 'user', 'text' => '質問']]);
            $this->fail('AiChatLlmFailedException が投げられませんでした。');
        } catch (AiChatLlmFailedException $e) {
            $this->assertSame(503, $e->upstreamStatus);
        }
    }

    public function test_throws_when_response_text_is_empty(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => []])]);

        $this->expectException(AiChatLlmFailedException::class);

        app(GeminiClient::class)->generate('system', [['role' => 'user', 'text' => '質問']]);
    }

    public function test_throws_without_upstream_status_on_connection_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        try {
            app(GeminiClient::class)->generate('system', [['role' => 'user', 'text' => '質問']]);
            $this->fail('AiChatLlmFailedException が投げられませんでした。');
        } catch (AiChatLlmFailedException $e) {
            $this->assertNull($e->upstreamStatus);
        }
    }
}
