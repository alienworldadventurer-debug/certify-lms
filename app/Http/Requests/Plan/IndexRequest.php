<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Models\Plan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class IndexRequest extends FormRequest
{
    protected $redirectRoute = 'admin.plans.index';

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Plan::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keyword.max' => '検索キーワードは100文字以内で入力してください。',
        ];
    }

    protected function prepareForValidation(): void
    {
        $keyword = $this->input('keyword');

        if (is_string($keyword)) {
            $keyword = trim($keyword);
            $this->merge(['keyword' => $keyword === '' ? null : $keyword]);
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        $response = redirect()
            ->route('admin.plans.index')
            ->withInput($this->only(['keyword', 'status']))
            ->withErrors($validator, $this->errorBag)
            ->with('error', $validator->errors()->first());

        throw new ValidationException($validator, $response, $this->errorBag);
    }
}
