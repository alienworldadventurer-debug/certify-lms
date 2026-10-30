<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;

/**
 * API 用の通知一覧取得 Action。
 *
 * tab=all は最新 20 件、tab=unread は未読のみの最新 20 件を返す。
 * unread_count は tab に関わらず全未読件数。
 */
final class ApiIndexAction
{
    /**
     * @return array{notifications: Collection<int, DatabaseNotification>, unread_count: int}
     */
    public function __invoke(User $user, string $tab): array
    {
        $query = $user->notifications()->latest()->limit(20);

        if ($tab === 'unread') {
            $query->whereNull('read_at');
        }

        return [
            'notifications' => $query->get(),
            'unread_count' => $user->unreadNotifications()->count(),
        ];
    }
}
