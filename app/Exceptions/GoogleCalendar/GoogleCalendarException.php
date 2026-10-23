<?php

declare(strict_types=1);

namespace App\Exceptions\GoogleCalendar;

use RuntimeException;
use Throwable;

/**
 * Google カレンダー連携の通信・認可で発生した失敗を表す例外。
 *
 * `isAuthError()` が true のときは認証エラー（HTTP 401 / リフレッシュ時の invalid_grant）を示す。
 * 予約・キャンセル時にだけ連携状態を error へ切り替える判定に使う。
 */
class GoogleCalendarException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $authError = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isAuthError(): bool
    {
        return $this->authError;
    }
}
