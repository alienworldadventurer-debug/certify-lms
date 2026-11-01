<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 受講生本人の面談一覧取得 Action。
 *
 * filter: upcoming(既定) / past / all。履歴一覧は scheduled_at の降順で返す。
 */
final class IndexAction
{
    public function __invoke(User $student, string $filter): LengthAwarePaginator
    {
        $query = Meeting::query()
            ->with(['enrollment.certification', 'coach'])
            ->forStudent($student)
            ->orderByDesc('scheduled_at');

        return match ($filter) {
            'past' => $query->past()->paginate(20),
            'all' => $query->paginate(20),
            default => $query->upcoming()->paginate(20),
        };
    }
}
