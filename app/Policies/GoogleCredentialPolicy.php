<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\GoogleCredential;
use App\Models\User;

/**
 * Google カレンダー連携 (GoogleCredential) の認可ポリシー。
 *
 * 連携の開始・解除はコーチ本人のみ可能。管理者・受講生は対象外。
 * 解除は自分の連携情報だけを対象にするため、呼び出し側は必ず本人の情報を扱う。
 */
class GoogleCredentialPolicy
{
    public function create(User $user): bool
    {
        return $user->role === UserRole::Coach;
    }

    public function delete(User $user): bool
    {
        return $user->role === UserRole::Coach;
    }
}
