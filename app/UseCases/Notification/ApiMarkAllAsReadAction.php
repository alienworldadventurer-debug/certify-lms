<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;

final class ApiMarkAllAsReadAction
{
    /**
     * @return array{unread_count: int}
     */
    public function __invoke(User $user): array
    {
        $user->unreadNotifications()->update(['read_at' => now()]);

        return [
            'unread_count' => 0,
        ];
    }
}
