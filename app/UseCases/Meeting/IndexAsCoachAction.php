<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * コーチ宛の面談一覧取得 Action。担当受講生 / 受講登録での絞り込みに対応する。
 *
 * upcoming: 次の面談を一番上に置く (昇順) / past + all: 直近の活動を一番上 (降順)
 */
final class IndexAsCoachAction
{
    /**
     * @param array{filter?: string|null, student?: int|string|null, enrollment?: int|string|null} $filters
     */
    public function __invoke(User $coach, array $filters): LengthAwarePaginator
    {
        $filter = $filters['filter'] ?? 'upcoming';
        $studentId = $filters['student'] ?? null;
        $enrollmentId = $filters['enrollment'] ?? null;

        $query = Meeting::query()
            ->with(['enrollment.certification', 'student'])
            ->forCoach($coach)
            ->when($studentId, fn ($q, $id) => $q->where('student_id', $id))
            ->when($enrollmentId, fn ($q, $id) => $q->where('enrollment_id', $id));

        return match ($filter) {
            'past' => $query->past()->orderByDesc('scheduled_at')->paginate(20),
            'all' => $query->orderByDesc('scheduled_at')->paginate(20),
            default => $query->upcoming()->orderBy('scheduled_at')->paginate(20),
        };
    }
}
