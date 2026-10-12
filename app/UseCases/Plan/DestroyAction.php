<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

final class DestroyAction
{
    public function __invoke(Plan $plan): bool
    {
        return DB::transaction(function () use ($plan): bool {
            $lockedPlan = Plan::query()
                ->lockForUpdate()
                ->findOrFail($plan->id);

            if (
                $lockedPlan->status !== PlanStatus::Draft
                || $lockedPlan->users()->withTrashed()->exists()
                || $lockedPlan->userPlanLogs()->exists()
            ) {
                return false;
            }

            $lockedPlan->delete();

            return true;
        });
    }
}
