<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知 API（一覧）のリクエスト。
 *
 * 不正な tab は 422 の JSON で返す（Web 版 Notification\IndexRequest のようなリダイレクトはしない）。
 */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', DatabaseNotification::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tab' => ['sometimes', 'string', 'in:all,unread'],
        ];
    }

    public function tab(): string
    {
        return $this->validated('tab', 'all');
    }
}
