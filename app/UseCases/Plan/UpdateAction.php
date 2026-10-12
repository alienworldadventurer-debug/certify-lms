<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateAction
{
    /**
     * @param array<string, mixed> $validated
     */
    public function __invoke(Plan $plan, User $admin, array $validated): Plan
    {
        return DB::transaction(function () use ($plan, $admin, $validated): Plan {
            $plan->fill([
                ...$validated,
                'sort_order' => $validated['sort_order'] ?? 0,
                'updated_by_user_id' => $admin->id,
            ]);
            $plan->setUpdatedAt(now());
            $plan->save();

            return $plan->fresh();
        });
    }
}
