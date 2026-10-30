<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\NotificationDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知 API 用 Resource。
 *
 * url の決定規則は Web 側の NotificationController::markAsRead と同じ:
 * admin_announcement は通知詳細、それ以外は accessibleTarget、取得できなければ通知一覧。
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DatabaseNotification $notification */
        $notification = $this->resource;
        $data = $notification->data ?? [];

        return [
            'id' => $notification->getKey(),
            'title' => $data['title'] ?? '通知',
            'message' => $data['message'] ?? ($data['body_preview'] ?? ''),
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
            'created_at_human' => $notification->created_at?->diffForHumans() ?? '',
            'url' => $this->resolveUrl($request, $notification, $data),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveUrl(Request $request, DatabaseNotification $notification, array $data): string
    {
        if (($data['notification_type'] ?? null) === 'admin_announcement') {
            return route('notifications.show', $notification->getKey());
        }

        $target = app(NotificationDeliveryService::class)
            ->accessibleTarget($request->user(), $notification);

        return $target ?? route('notifications.index');
    }
}
