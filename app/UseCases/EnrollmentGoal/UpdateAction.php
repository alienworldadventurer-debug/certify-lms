<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

final class UpdateAction
{
    /**
     * @param array{title: string, description?: ?string, target_date?: ?string} $validated
     */
    public function __invoke(EnrollmentGoal $goal, array $validated): EnrollmentGoal
    {
        $goal->update($validated);

        return $goal->refresh();
    }
}
