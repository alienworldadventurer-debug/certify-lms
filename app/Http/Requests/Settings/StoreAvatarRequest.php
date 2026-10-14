<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\URL;

class StoreAvatarRequest extends FormRequest
{
    protected function getRedirectUrl(): string
    {
        return URL::route('settings.profile.edit', ['tab' => 'profile']);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('updateAvatar', $user);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => '画像を選択してください。',
            'avatar.file' => 'PNG／JPG／WebP形式の画像を選択してください。',
            'avatar.mimes' => 'PNG／JPG／WebP形式の画像を選択してください。',
            'avatar.max' => '2MB以内の画像を選択してください。',
        ];
    }
}
