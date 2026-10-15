<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        if (! $note instanceof EnrollmentNote || $this->user() === null) {
            return false;
        }

        Gate::forUser($this->user())->authorize('delete', $note);

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
