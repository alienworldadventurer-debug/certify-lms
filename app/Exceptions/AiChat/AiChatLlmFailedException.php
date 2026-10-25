<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gemini API の呼び出しに失敗したことを表す例外（HTTP 502）。
 *
 * `upstreamStatus` は Gemini 側の HTTP ステータス（接続失敗など取得できない場合は null）。
 * `detail` はエラーメッセージ行に保存する内部向けの原因。受講生向けの文言は message を使う。
 */
final class AiChatLlmFailedException extends HttpException
{
    public function __construct(
        public readonly ?int $upstreamStatus,
        public readonly string $detail,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(502, 'AI が応答できませんでした。しばらく時間をおいて再試行してください。', $previous);
    }

    /**
     * JSON リクエストには画面 JS が参照する upstream_status を含める。
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'upstream_status' => $this->upstreamStatus,
        ], 502);
    }
}
