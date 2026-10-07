<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use App\Models\MeetingPack;
use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', MeetingPack::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string'],
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
