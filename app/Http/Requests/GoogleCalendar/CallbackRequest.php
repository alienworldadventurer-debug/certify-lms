<?php

declare(strict_types=1);

namespace App\Http\Requests\GoogleCalendar;

use App\Models\GoogleCredential;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Session;

/**
 * Google の OAuth コールバックを検証するリクエスト。
 *
 * 検証は `error`（利用者のキャンセル等）を先に見て、その後 `state` と `code` を確認する。
 * `state` はセッションに保存した値と照合し、照合時に必ずセッションから取り除いて再利用を防ぐ。
 * 失敗時の画面遷移とフラッシュメッセージはコントローラー側で扱う。
 */
class CallbackRequest extends FormRequest
{
    public const SESSION_STATE_KEY = 'google_calendar_oauth_state';

    public const SESSION_REDIRECT_KEY = 'google_calendar_oauth_redirect_path';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', GoogleCredential::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'error' => ['nullable', 'string', 'max:255'],
            'code' => ['required_without:error', 'nullable', 'string', 'max:2048'],
            'state' => [
                'required_without:error',
                'nullable',
                'string',
                'max:255',
                $this->matchesStoredState(),
            ],
        ];
    }

    /**
     * セッションに保存された state と一致するかを検証する。一致・不一致に関わらず値は破棄する。
     */
    private function matchesStoredState(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $expected = Session::pull(self::SESSION_STATE_KEY);

            if (! is_string($expected) || $expected === '' || ! hash_equals($expected, (string) $value)) {
                $fail('Google連携の確認に失敗しました。もう一度お試しください。');
            }
        };
    }
}
