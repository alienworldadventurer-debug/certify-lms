<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * 1 日の送信上限に達したことを表す例外（HTTP 429）。
 */
final class AiChatDailyLimitExceededException extends TooManyRequestsHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(null, '本日の利用上限に達しました。明日 0:00 以降に再度ご利用ください。', $previous);
    }
}
