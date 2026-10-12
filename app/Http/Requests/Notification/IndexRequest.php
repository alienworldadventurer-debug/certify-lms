<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Notifications\DatabaseNotification;

class IndexRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $query = $this->query->all();

        if (array_key_exists('tab', $query)
            && (! is_string($query['tab']) || ! in_array($query['tab'], ['all', 'unread'], true))) {
            throw new HttpResponseException(
                redirect()
                    ->route('notifications.index')
                    ->with('warning', '通知の表示条件が不正なため、全件を表示しています。'),
            );
        }

        return [
            'tab' => ['nullable', 'string', 'in:all,unread'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', DatabaseNotification::class) ?? false;
    }

    /**
     * @return array{tab: string}
     */
    public function filters(): array
    {
        return [
            'tab' => $this->query('tab', 'all'),
        ];
    }
}
