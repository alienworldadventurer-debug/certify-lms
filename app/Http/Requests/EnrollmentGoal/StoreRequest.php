<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $enrollment = $this->route('enrollment');

        if (! $enrollment instanceof Enrollment || $this->user() === null) {
            return false;
        }

        Gate::forUser($this->user())->authorize('create', [EnrollmentGoal::class, $enrollment]);

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'target_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => '目標を入力してください。',
            'title.string' => '目標は文字列で入力してください。',
            'title.max' => '目標は100文字以内で入力してください。',
            'description.string' => '詳細は文字列で入力してください。',
            'description.max' => '詳細は1000文字以内で入力してください。',
            'target_date.date' => '目標期日を正しく入力してください。',
        ];
    }
}
