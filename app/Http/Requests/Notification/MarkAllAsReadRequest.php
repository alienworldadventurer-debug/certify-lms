<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;

class MarkAllAsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('markAllAsRead', DatabaseNotification::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
