<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

final class ApiMarkAsReadAction
{
    /**
     * 通知を既読にし、既読後の未読件数を返す。
     *
     * @return array{unread_count: int}
     */
    public function __invoke(User $user, DatabaseNotification $notification): array
    {
        $user->notifications()
            ->whereKey($notification->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return [
            'unread_count' => $user->unreadNotifications()->count(),
        ];
    }
}
