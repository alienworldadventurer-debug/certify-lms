<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MeetingPack;
use App\Models\User;

class MeetingPackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, MeetingPack $meetingPack): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, MeetingPack $meetingPack): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function delete(User $user, MeetingPack $meetingPack): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function publish(User $user, MeetingPack $meetingPack): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function archive(User $user, MeetingPack $meetingPack): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function unarchive(User $user, MeetingPack $meetingPack): bool
    {
        return $user->role === UserRole::Admin;
    }
}
