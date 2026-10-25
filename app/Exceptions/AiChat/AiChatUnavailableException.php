<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * API キー未設定などで AI 相談機能を提供できないことを表す例外（HTTP 500）。
 */
final class AiChatUnavailableException extends HttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(500, 'AI 相談機能は現在ご利用いただけません', $previous);
    }
}
