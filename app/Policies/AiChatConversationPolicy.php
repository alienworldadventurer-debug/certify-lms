<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\User;

/**
 * AI 相談の会話に対する認可ポリシー。
 *
 * 受講生（学習中）本人の会話のみ操作可能。他人の会話・他ロールはすべて false（403）。
 * 存在しない会話は route model binding の段階で 404 になる。
 */
class AiChatConversationPolicy
{
    public function create(User $user): bool
    {
        return $this->isActiveStudent($user);
    }

    public function view(User $user, AiChatConversation $conversation): bool
    {
        return $this->isOwner($user, $conversation);
    }

    public function update(User $user, AiChatConversation $conversation): bool
    {
        return $this->isOwner($user, $conversation);
    }

    public function delete(User $user, AiChatConversation $conversation): bool
    {
        return $this->isOwner($user, $conversation);
    }

    public function sendMessage(User $user, AiChatConversation $conversation): bool
    {
        return $this->isOwner($user, $conversation);
    }

    private function isOwner(User $user, AiChatConversation $conversation): bool
    {
        return $this->isActiveStudent($user)
            && $conversation->user_id === $user->id;
    }

    private function isActiveStudent(User $user): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress;
    }
}
