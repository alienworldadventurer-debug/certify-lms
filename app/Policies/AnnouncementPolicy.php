<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\User;

/**
 * お知らせ配信（管理者専用）の認可ルール。配信履歴の閲覧と配信の実行のみを許可する。
 * 編集・削除・再配信のルートは設けないため、対応するメソッドも持たない。
 */
class AnnouncementPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function view(User $auth, Announcement $announcement): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
