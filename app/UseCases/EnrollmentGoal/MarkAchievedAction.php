<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

final class MarkAchievedAction
{
    public function __invoke(EnrollmentGoal $goal): void
    {
        DB::transaction(function () use ($goal): void {
            $lockedGoal = EnrollmentGoal::query()
                ->whereKey($goal->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedGoal->achieved_at === null) {
                $lockedGoal->update(['achieved_at' => now()]);
            }
        });
    }
}
