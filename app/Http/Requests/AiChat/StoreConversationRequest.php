<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 新しい相談の開始時の入力検証。
 *
 * `message` は任意（ウィジェットの会話先行作成は本文なしで送る）。
 * 空白のみの入力は TrimStrings / ConvertEmptyStringsToNull により null となり、本文なし扱いになる。
 */
class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AiChatConversation::class) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'in:full-screen,widget'],
            'section_id' => ['nullable', 'string', 'exists:sections,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.string' => '質問内容は文字列で入力してください。',
            'message.max' => '質問内容は2,000文字以内で入力してください。',
        ];
    }
}
