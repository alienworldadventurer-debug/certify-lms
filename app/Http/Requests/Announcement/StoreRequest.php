<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRequest extends FormRequest
{
    protected $redirectRoute = 'admin.announcements.create';

    public function authorize(): bool
    {
        return $this->user()?->can('create', Announcement::class) ?? false;
    }

    /**
     * 対象資格・対象受講生は、配信対象が一致する場合のみ検証する（exclude_unless）。
     * 配信対象と無関係な値の検出は withValidator() で行う。
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'target_type' => ['required', Rule::enum(AnnouncementTargetType::class)],
            'target_certification_id' => [
                'exclude_unless:target_type,'.AnnouncementTargetType::Certification->value,
                'required',
                'string',
                'ulid',
                Rule::exists('certifications', 'id'),
            ],
            'target_user_id' => [
                'exclude_unless:target_type,'.AnnouncementTargetType::User->value,
                'required',
                'string',
                'ulid',
                Rule::exists('users', 'id')
                    ->where('role', UserRole::Student->value)
                    ->where('status', UserStatus::InProgress->value)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'タイトルは必須です。',
            'title.max' => 'タイトルは200文字以内で入力してください。',
            'body.required' => '本文は必須です。',
            'body.max' => '本文は5,000文字以内で入力してください。',
            'target_type.required' => '配信対象は必須です。',
            'target_type.Illuminate\Validation\Rules\Enum' => '配信対象を正しく指定してください。',
            'target_certification_id.required' => '配信対象が「資格指定」の場合、対象資格は必須です。',
            'target_certification_id.string' => '選択した対象資格は指定できません。',
            'target_certification_id.ulid' => '選択した対象資格は指定できません。',
            'target_certification_id.exists' => '選択した対象資格は指定できません。',
            'target_user_id.required' => '配信対象が「ユーザー指定」の場合、対象受講生は必須です。',
            'target_user_id.string' => '選択した対象受講生は指定できません。',
            'target_user_id.ulid' => '選択した対象受講生は指定できません。',
            'target_user_id.exists' => '選択した対象受講生は指定できません。',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $targetType = AnnouncementTargetType::tryFrom((string) $this->input('target_type'));

            if ($targetType === null) {
                return;
            }

            if ($targetType !== AnnouncementTargetType::Certification && $this->filled('target_certification_id')) {
                $validator->errors()->add(
                    'target_certification_id',
                    sprintf('配信対象が「%s」の場合、資格は指定できません。', $targetType->label()),
                );
            }

            if ($targetType !== AnnouncementTargetType::User && $this->filled('target_user_id')) {
                $validator->errors()->add(
                    'target_user_id',
                    sprintf('配信対象が「%s」の場合、受講生は指定できません。', $targetType->label()),
                );
            }
        });
    }
}
