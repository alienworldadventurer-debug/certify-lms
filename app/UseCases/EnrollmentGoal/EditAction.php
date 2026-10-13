<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

final class EditAction
{
    public function __invoke(EnrollmentGoal $goal): EnrollmentGoal
    {
        return $goal->loadMissing('enrollment');
    }
}
