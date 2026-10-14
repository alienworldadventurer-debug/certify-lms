<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\URL;

class UpdatePasswordRequest extends FormRequest
{
    protected function getRedirectUrl(): string
    {
        return URL::route('settings.profile.edit', ['tab' => 'password']);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('updatePassword', $user);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8'],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => '現在のパスワードを入力してください。',
            'current_password.string' => '現在のパスワードは文字列で入力してください。',
            'current_password.current_password' => '現在のパスワードが正しくありません。',
            'password.required' => '新しいパスワードを入力してください。',
            'password.string' => '新しいパスワードは文字列で入力してください。',
            'password.min' => '新しいパスワードは8文字以上で入力してください。',
            'password_confirmation.required' => '新しいパスワード（確認）を入力してください。',
            'password_confirmation.string' => '新しいパスワード（確認）は文字列で入力してください。',
            'password_confirmation.same' => '新しいパスワードが一致しません。',
        ];
    }
}
