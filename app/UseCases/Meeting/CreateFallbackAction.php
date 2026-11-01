<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 予約画面エントリ(default 資格が未設定の場合)で、選択肢として表示する受講登録一覧を取得する Action。
 *
 * 対象は learning / passed の受講登録のみ。
 */
final class CreateFallbackAction
{
    /**
     * @return Collection<int, Enrollment>
     */
    public function __invoke(?User $user): Collection
    {
        return $user
            ?->enrollments()
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->with('certification')
            ->get() ?? collect();
    }
}
