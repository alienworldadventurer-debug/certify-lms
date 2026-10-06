<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Enums\CertificationStatus;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', QaThread::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'certification_id' => [
                'bail',
                'required',
                'string',
                'ulid',
                Rule::exists('certifications', 'id')->where('status', CertificationStatus::Published->value),
            ],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'certification_id.required' => '資格は必須です。',
            'certification_id.string' => '選択した資格は選択できません。',
            'certification_id.ulid' => '選択した資格は選択できません。',
            'certification_id.exists' => '選択した資格は選択できません。',
            'title.required' => 'タイトルは必須です。',
            'title.max' => 'タイトルは200文字以内で入力してください。',
            'body.required' => '質問本文は必須です。',
            'body.max' => '質問本文は5,000文字以内で入力してください。',
        ];
    }
}
