<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof Plan
            && ($this->user()?->can('update', $plan) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'default_meeting_quota' => ['required', 'integer', 'min:0', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'プラン名は必須です。',
            'name.max' => 'プラン名は100文字以内で入力してください。',
            'description.max' => '説明は2,000文字以内で入力してください。',
            'duration_days.required' => '受講期間は必須です。',
            'duration_days.integer' => '受講期間は1〜3,650日の整数で入力してください。',
            'duration_days.min' => '受講期間は1〜3,650日の整数で入力してください。',
            'duration_days.max' => '受講期間は1〜3,650日の整数で入力してください。',
            'default_meeting_quota.required' => '初期付与面談回数は必須です。',
            'default_meeting_quota.integer' => '初期付与面談回数は0〜1,000の整数で入力してください。',
            'default_meeting_quota.min' => '初期付与面談回数は0〜1,000の整数で入力してください。',
            'default_meeting_quota.max' => '初期付与面談回数は0〜1,000の整数で入力してください。',
            'sort_order.integer' => '並び順は0〜4,294,967,295の整数で入力してください。',
            'sort_order.min' => '並び順は0〜4,294,967,295の整数で入力してください。',
            'sort_order.max' => '並び順は0〜4,294,967,295の整数で入力してください。',
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        foreach (['name', 'description'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);
                $values[$field] = $value === '' ? null : $value;
            }
        }

        $this->merge($values);
    }
}
