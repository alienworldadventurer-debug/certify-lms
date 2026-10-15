<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        if (! $note instanceof EnrollmentNote || $this->user() === null) {
            return false;
        }

        Gate::forUser($this->user())->authorize('update', $note);

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'メモ本文を入力してください。',
            'body.string' => 'メモ本文は文字列で入力してください。',
            'body.max' => 'メモ本文は2000文字以内で入力してください。',
        ];
    }
}
