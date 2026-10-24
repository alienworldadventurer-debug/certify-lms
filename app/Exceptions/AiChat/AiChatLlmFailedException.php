<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\BadGatewayHttpException;

/**
 * Gemini API の呼び出しに失敗したことを表す例外（HTTP 502）。
 *
 * `upstreamStatus` は Gemini 側の HTTP ステータス（接続失敗など取得できない場合は null）。
 * `detail` はエラーメッセージ行に保存する内部向けの原因。受講生向けの文言は message を使う。
 */
final class AiChatLlmFailedException extends BadGatewayHttpException
{
    public function __construct(
        public readonly ?int $upstreamStatus,
        public readonly string $detail,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('AI が応答できませんでした。しばらく時間をおいて再試行してください。', $previous);
    }
}
