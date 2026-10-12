<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, Plan $plan): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, Plan $plan): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function publish(User $user, Plan $plan): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function archive(User $user, Plan $plan): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function unarchive(User $user, Plan $plan): bool
    {
        return $user->role === UserRole::Admin;
    }
}
