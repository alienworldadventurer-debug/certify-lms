<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 新しい相談の開始時の入力検証。
 *
 * `message` は任意（ウィジェットの会話先行作成は本文なしで送る）。
 * 空白のみの入力は TrimStrings / ConvertEmptyStringsToNull により null となり、本文なし扱いになる。
 * `section_id` は受講中の資格に属する公開済みの Section のみ許可する。
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
            'section_id' => ['nullable', 'string', $this->accessibleSectionRule()],
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

    private function accessibleSectionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $this->isAccessibleSection((string) $value)) {
                $fail(trans('validation.exists', ['attribute' => $attribute]));
            }
        };
    }

    private function isAccessibleSection(string $sectionId): bool
    {
        $certificationIds = Enrollment::query()
            ->where('user_id', $this->user()?->id)
            ->where('status', EnrollmentStatus::Learning->value)
            ->pluck('certification_id');

        return Section::query()
            ->published()
            ->whereKey($sectionId)
            ->whereHas('chapter.part', fn ($q) => $q->whereIn('certification_id', $certificationIds))
            ->exists();
    }
}
