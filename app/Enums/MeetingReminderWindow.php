<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 面談リマインダー通知の配信タイミング (window) を表す Enum。
 *
 * - Eve: 開始日時が翌日の面談に対し、前日 18:00 に配信する。
 * - OneHourBefore: 開始まで 0 分超〜60 分以内の面談に対し、5 分間隔で配信する。
 *
 * 重複防止の判定キーとして通知データの reminder_window にも保存する。
 */
enum MeetingReminderWindow: string
{
    case Eve = 'eve';
    case OneHourBefore = 'one_hour_before';

    public function label(): string
    {
        return match ($this) {
            self::Eve => '前日リマインド',
            self::OneHourBefore => '1時間前リマインド',
        };
    }
}
