<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    /**
     * 公開状態の判定は Controller/Service 側で行う(非公開は 422 エラーページ)。
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'meeting_pack_id' => [
                'bail',
                'required',
                'string',
                'ulid',
                Rule::exists('meeting_packs', 'id'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'meeting_pack_id.required' => '面談パックを選択してください。',
            'meeting_pack_id.string' => '面談パックの指定が正しくありません。',
            'meeting_pack_id.ulid' => '面談パックの指定が正しくありません。',
            'meeting_pack_id.exists' => '面談パックの指定が正しくありません。',
        ];
    }
}
