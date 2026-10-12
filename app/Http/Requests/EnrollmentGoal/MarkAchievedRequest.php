<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class MarkAchievedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $goal = $this->route('goal');

        if (! $goal instanceof EnrollmentGoal || $this->user() === null) {
            return false;
        }

        Gate::forUser($this->user())->authorize('markAchieved', $goal);

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
