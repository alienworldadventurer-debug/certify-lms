<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;

class MarkAsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('viewAny', DatabaseNotification::class)) {
            return false;
        }

        $notification = $this->route('notification');
        $notificationId = $notification instanceof DatabaseNotification
            ? $notification->getKey()
            : $notification;

        if (! is_string($notificationId)) {
            abort(404);
        }

        $notification = $user->notifications()->whereKey($notificationId)->first();

        if ($notification === null) {
            abort(404);
        }

        return $user->can('markAsRead', $notification);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
