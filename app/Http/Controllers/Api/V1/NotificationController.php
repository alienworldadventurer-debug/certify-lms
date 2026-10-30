<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\IndexRequest;
use App\Http\Requests\Notification\MarkAllAsReadRequest;
use App\Http\Requests\Notification\MarkAsReadRequest;
use App\Http\Resources\NotificationResource;
use App\UseCases\Notification\ApiIndexAction;
use App\UseCases\Notification\ApiMarkAllAsReadAction;
use App\UseCases\Notification\ApiMarkAsReadAction;
use Illuminate\Http\JsonResponse;

final class NotificationController extends Controller
{
    public function index(IndexRequest $request, ApiIndexAction $action): JsonResponse
    {
        $result = $action($request->user(), $request->tab());

        return response()->json([
            'data' => NotificationResource::collection($result['notifications'])->resolve(),
            'unread_count' => $result['unread_count'],
        ]);
    }

    public function markAsRead(
        string $notification,
        MarkAsReadRequest $request,
        ApiMarkAsReadAction $action,
    ): JsonResponse {
        $owned = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        return response()->json($action($request->user(), $owned));
    }

    public function markAllAsRead(MarkAllAsReadRequest $request, ApiMarkAllAsReadAction $action): JsonResponse
    {
        return response()->json($action($request->user()));
    }
}
