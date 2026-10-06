<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class QaReplyPolicy
{
    public function view(User $user, QaReply $reply): bool|Response
    {
        $reply->loadMissing('thread');

        if (! $this->canViewThread($user, $reply)) {
            return Response::denyAsNotFound();
        }

        return true;
    }

    public function create(User $user, QaThread $thread): bool|Response
    {
        if (! Gate::forUser($user)->inspect('view', $thread)->allowed()) {
            return Response::denyAsNotFound();
        }

        return in_array($user->role, [UserRole::Student, UserRole::Coach], true)
            && $user->status === UserStatus::InProgress;
    }

    public function update(User $user, QaReply $reply): bool|Response
    {
        if (! $this->canViewThread($user, $reply)) {
            return Response::denyAsNotFound();
        }

        return in_array($user->role, [UserRole::Student, UserRole::Coach], true)
            && $user->status === UserStatus::InProgress
            && $reply->user_id === $user->id;
    }

    public function delete(User $user, QaReply $reply): bool|Response
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        if (! $this->canViewThread($user, $reply)) {
            return Response::denyAsNotFound();
        }

        return in_array($user->role, [UserRole::Student, UserRole::Coach], true)
            && $user->status === UserStatus::InProgress
            && $reply->user_id === $user->id;
    }

    private function canViewThread(User $user, QaReply $reply): bool
    {
        $reply->loadMissing('thread');

        return $reply->thread !== null
            && Gate::forUser($user)->inspect('view', $reply->thread)->allowed();
    }
}
