<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use App\Models\MeetingPack;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MeetingPack::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'meeting_count' => ['required', 'integer', 'min:1', 'max:100'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'パック名は必須です。',
            'name.max' => 'パック名は100文字以内で入力してください。',
            'description.max' => '説明は2,000文字以内で入力してください。',
            'meeting_count.required' => '面談回数は必須です。',
            'meeting_count.integer' => '面談回数は1〜100の整数で入力してください。',
            'meeting_count.min' => '面談回数は1〜100の整数で入力してください。',
            'meeting_count.max' => '面談回数は1〜100の整数で入力してください。',
            'price.required' => '価格は必須です。',
            'price.integer' => '価格は0〜1,000,000円の整数で入力してください。',
            'price.min' => '価格は0〜1,000,000円の整数で入力してください。',
            'price.max' => '価格は0〜1,000,000円の整数で入力してください。',
            'stripe_price_id.max' => 'Stripe Price IDは255文字以内で入力してください。',
            'sort_order.integer' => '並び順は0〜1,000,000の整数で入力してください。',
            'sort_order.min' => '並び順は0〜1,000,000の整数で入力してください。',
            'sort_order.max' => '並び順は0〜1,000,000の整数で入力してください。',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStringInputs();
    }

    private function trimStringInputs(): void
    {
        $values = [];

        foreach (['name', 'description', 'stripe_price_id'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);
                $values[$field] = $value === '' ? null : $value;
            }
        }

        $this->merge($values);
    }
}
