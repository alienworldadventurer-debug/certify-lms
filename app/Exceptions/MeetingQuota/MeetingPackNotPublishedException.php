<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 公開されていない面談パックの購入が試行された際に throw される(HTTP 422)。
 * メッセージは表示せず、422 エラーページで応答する。
 */
final class MeetingPackNotPublishedException extends UnprocessableEntityHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('', $previous);
    }
}
