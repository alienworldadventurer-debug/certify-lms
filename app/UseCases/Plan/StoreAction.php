<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StoreAction
{
    /**
     * @param array<string, mixed> $validated
     */
    public function __invoke(User $admin, array $validated): Plan
    {
        return DB::transaction(fn () => Plan::query()->create([
            ...$validated,
            'sort_order' => $validated['sort_order'] ?? 0,
            'status' => PlanStatus::Draft,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]));
    }
}
