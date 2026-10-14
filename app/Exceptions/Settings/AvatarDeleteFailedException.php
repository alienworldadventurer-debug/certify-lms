<?php

declare(strict_types=1);

namespace App\Exceptions\Settings;

use RuntimeException;

final class AvatarDeleteFailedException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('アイコン画像を削除できませんでした。もう一度お試しください。', 0, $previous);
    }
}
