<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Google 連携情報 (GoogleCredential) の状態を表す Enum。
 *
 * - Active: 連携中。空き判定・面談予定の登録／削除を行う。
 * - Error: 現在は使用しない(認証エラー時は連携情報を削除して未連携に戻す)。
 */
enum GoogleCredentialStatus: string
{
    case Active = 'active';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Active => '連携中',
            self::Error => '連携エラー',
        };
    }
}
