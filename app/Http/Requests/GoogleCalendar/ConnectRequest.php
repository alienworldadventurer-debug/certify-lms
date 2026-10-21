<?php

declare(strict_types=1);

namespace App\Http\Requests\GoogleCalendar;

use App\Models\GoogleCredential;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Google カレンダー連携開始リクエスト。
 *
 * `redirect_path` は連携後の戻り先。外部サイトへの遷移を防ぐため、
 * 「/」で始まり「//」や「\」を含まない相対パスだけを受け入れる。
 * 不正な値は検証エラーにせず無視し、呼び出し側で既定値を使う。
 */
class ConnectRequest extends FormRequest
{
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
            'redirect_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 戻り先として安全な相対パスを返す。不正な場合は null。
     */
    public function safeRedirectPath(): ?string
    {
        $path = $this->input('redirect_path');

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return $path;
    }
}
