<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

final class DestroyAction
{
    public function __invoke(EnrollmentGoal $goal): void
    {
        $goal->delete();
    }
}
