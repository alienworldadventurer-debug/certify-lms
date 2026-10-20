<?php

declare(strict_types=1);

namespace App\Console\Commands\Mentoring;

use App\Enums\MeetingReminderWindow;
use App\UseCases\Meeting\SendMeetingReminderAction;
use Illuminate\Console\Command;

/**
 * 面談リマインダーを window 指定で配信する Schedule Command。
 *
 * `--window=eve`（前日）と `--window=one_hour_before`（1時間前）を Kernel から別々に起動する。
 * 重複配信は SendMeetingReminderAction 側の既存通知チェックで防ぐ。
 */
class SendMeetingRemindersCommand extends Command
{
    protected $signature = 'meetings:send-reminders {--window= : 配信 window（eve / one_hour_before）}';

    protected $description = '面談リマインダー通知を window 指定で配信する';

    public function handle(SendMeetingReminderAction $action): int
    {
        $window = MeetingReminderWindow::tryFrom((string) $this->option('window'));

        if ($window === null) {
            $this->error('--window は eve または one_hour_before を指定してください。');

            return self::FAILURE;
        }

        $counts = $action($window);

        $this->info("面談リマインダー（{$window->label()}）: 配信 {$counts['delivered']} 件 / スキップ {$counts['skipped']} 件 / 失敗 {$counts['failed']} 件");

        return self::SUCCESS;
    }
}
