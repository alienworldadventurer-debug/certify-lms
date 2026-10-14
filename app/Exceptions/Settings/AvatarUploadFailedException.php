<?php

declare(strict_types=1);

namespace App\Exceptions\Settings;

use RuntimeException;

final class AvatarUploadFailedException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('画像をアップロードできませんでした。もう一度お試しください。', 0, $previous);
    }
}
