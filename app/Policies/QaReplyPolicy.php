<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class QaReplyPolicy
{
    public function view(User $user, QaReply $reply): bool
    {
        $reply->loadMissing('thread');

        return $reply->thread !== null
            && Gate::forUser($user)->allows('view', $reply->thread);
    }

    public function create(User $user, QaThread $thread): bool
    {
        return in_array($user->role, [UserRole::Student, UserRole::Coach], true)
            && $user->status === UserStatus::InProgress
            && Gate::forUser($user)->allows('view', $thread);
    }

    public function update(User $user, QaReply $reply): bool
    {
        return in_array($user->role, [UserRole::Student, UserRole::Coach], true)
            && $user->status === UserStatus::InProgress
            && $reply->user_id === $user->id
            && $this->view($user, $reply);
    }

    public function delete(User $user, QaReply $reply): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return in_array($user->role, [UserRole::Student, UserRole::Coach], true)
            && $user->status === UserStatus::InProgress
            && $reply->user_id === $user->id
            && $this->view($user, $reply);
    }
}
