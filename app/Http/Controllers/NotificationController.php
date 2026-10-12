<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Notification\IndexRequest;
use App\Http\Requests\Notification\MarkAllAsReadRequest;
use App\Http\Requests\Notification\MarkAsReadRequest;
use App\Services\NotificationDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class NotificationController extends Controller
{
    public function index(IndexRequest $request): View
    {
        $user = $request->user();
        $tab = $request->filters()['tab'];
        $query = $user->notifications()->latest();

        if ($tab === 'unread') {
            $query->whereNull('read_at');
        }

        return view('notifications.index', [
            'notifications' => $query->paginate(20)->appends($request->query()),
            'unreadCount' => $user->unreadNotifications()->count(),
            'tab' => $tab,
        ]);
    }

    public function markAsRead(
        string $notification,
        MarkAsReadRequest $request,
        NotificationDeliveryService $delivery,
    ): RedirectResponse {
        $user = $request->user();
        $ownedNotification = $user->notifications()->whereKey($notification)->firstOrFail();
        $ownedNotification->markAsRead();

        $target = $delivery->accessibleTarget($user, $ownedNotification);

        if ($target === null) {
            return redirect()
                ->route('notifications.index')
                ->with('warning', '通知の対象ページを表示できないため、通知一覧に戻りました。');
        }

        return redirect()->to($target);
    }

    public function markAllAsRead(MarkAllAsReadRequest $request): RedirectResponse
    {
        $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return redirect()
            ->route('notifications.index')
            ->with('success', 'すべての通知を既読にしました。');
    }
}
