<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class QaThreadPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return $user->status === UserStatus::InProgress
            && in_array($user->role, [UserRole::Student, UserRole::Coach], true);
    }

    public function view(User $user, QaThread $thread): bool|Response
    {
        if (! $this->canViewThread($user, $thread)) {
            return Response::denyAsNotFound();
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress;
    }

    public function update(User $user, QaThread $thread): bool|Response
    {
        if (! $this->canViewThread($user, $thread)) {
            return Response::denyAsNotFound();
        }

        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress
            && $thread->user_id === $user->id;
    }

    public function delete(User $user, QaThread $thread): bool|Response
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        if (! $this->canViewThread($user, $thread)) {
            return Response::denyAsNotFound();
        }

        // A reply-count conflict must remain a 409 handled by the action, not a policy 403.
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress
            && $thread->user_id === $user->id;
    }

    public function resolve(User $user, QaThread $thread): bool|Response
    {
        if (! $this->canViewThread($user, $thread)) {
            return Response::denyAsNotFound();
        }

        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress
            && $thread->user_id === $user->id;
    }

    public function unresolve(User $user, QaThread $thread): bool|Response
    {
        return $this->resolve($user, $thread);
    }

    private function canViewCertification(User $user, Certification $certification): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->status !== UserStatus::InProgress
            || $certification->status !== CertificationStatus::Published) {
            return false;
        }

        return match ($user->role) {
            UserRole::Student => true,
            UserRole::Coach => $certification->coaches()
                ->where('users.id', $user->id)
                ->exists(),
            default => false,
        };
    }

    private function canViewThread(User $user, QaThread $thread): bool
    {
        $thread->loadMissing('certification');

        return $thread->certification !== null
            && $this->canViewCertification($user, $thread->certification);
    }
}
