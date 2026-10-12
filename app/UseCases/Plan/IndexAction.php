<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IndexAction
{
    public function __invoke(?string $keyword, ?PlanStatus $status): LengthAwarePaginator
    {
        return Plan::query()
            ->withCount([
                'users' => fn ($query) => $query
                    ->where('role', UserRole::Student)
                    ->where('status', UserStatus::InProgress),
            ])
            ->when($keyword !== null, fn ($query) => $query->where('name', 'like', '%'.$keyword.'%'))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->ordered()
            ->paginate(20)
            ->withQueryString();
    }
}
