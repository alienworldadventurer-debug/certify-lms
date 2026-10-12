<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
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
}
