<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Google 連携情報 (GoogleCredential) の状態を表す Enum。
 *
 * - Active: 連携中。空き判定・面談予定の登録／削除を行う。
 * - Error: 連携エラー(許可取消・トークン失効)。Google 連携は行わない。
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
