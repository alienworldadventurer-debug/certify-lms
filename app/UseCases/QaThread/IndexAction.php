<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class IndexAction
{
    /**
     * @param array{certification_id: ?string, status: ?string, keyword: ?string} $filters
     *
     * @return array{
     *     threads: LengthAwarePaginator,
     *     certifications: Collection<int, Certification>,
     *     filters: array{certification_id: string, status: string, keyword: string},
     *     publishedStatus: CertificationStatus
     * }
     */
    public function __invoke(User $viewer, array $filters): array
    {
        $certifications = $this->availableCertifications($viewer);
        $query = QaThread::query()
            ->with(['user', 'certification'])
            ->withCount('replies');

        if ($viewer->role !== UserRole::Admin) {
            $query->whereHas('certification', function (Builder $certificationQuery) use ($viewer): void {
                $certificationQuery->where('status', CertificationStatus::Published->value);

                if ($viewer->role === UserRole::Coach) {
                    $certificationQuery->whereHas(
                        'coaches',
                        fn (Builder $coachQuery) => $coachQuery->where('users.id', $viewer->id),
                    );
                }
            });
        }

        if ($filters['certification_id'] !== null && $filters['certification_id'] !== '') {
            $query->where('certification_id', $filters['certification_id']);
        }

        if ($filters['status'] === 'unresolved') {
            $query->where('status', QaThreadStatus::Open->value);
        } elseif ($filters['status'] === 'resolved') {
            $query->where('status', QaThreadStatus::Resolved->value);
        }

        $keyword = $filters['keyword'];
        if ($keyword !== null && $keyword !== '') {
            $query->where(function (Builder $keywordQuery) use ($keyword): void {
                $keywordQuery
                    ->where('title', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('body', 'LIKE', '%'.$keyword.'%')
                    ->orWhereHas('replies', fn (Builder $replyQuery) => $replyQuery->where('body', 'LIKE', '%'.$keyword.'%'));
            });
        }

        return [
            'threads' => $query->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'certifications' => $certifications,
            'filters' => [
                'certification_id' => $filters['certification_id'] ?? '',
                'status' => $filters['status'] ?? '',
                'keyword' => $keyword ?? '',
            ],
            'publishedStatus' => CertificationStatus::Published,
        ];
    }

    /**
     * @return Collection<int, Certification>
     */
    public function availableCertifications(User $viewer): Collection
    {
        $query = Certification::query()->orderBy('name');

        if ($viewer->role !== UserRole::Admin) {
            $query->where('status', CertificationStatus::Published->value);

            if ($viewer->role === UserRole::Coach) {
                $query->assignedTo($viewer);
            }
        }

        return $query->get();
    }
}
