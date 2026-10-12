<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Notifications\DatabaseNotification;

class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return in_array($user->role, [UserRole::Student, UserRole::Coach], true)
            && in_array($user->status, [UserStatus::InProgress, UserStatus::Graduated], true);
    }

    public function markAsRead(User $user, DatabaseNotification $notification): bool|Response
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($notification->notifiable_type !== $user->getMorphClass()
            || $notification->notifiable_id !== $user->getKey()) {
            return Response::denyAsNotFound();
        }

        return true;
    }

    public function markAllAsRead(User $user): bool
    {
        return $this->viewAny($user);
    }
}
