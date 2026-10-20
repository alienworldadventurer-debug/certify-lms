<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\BusinessEventNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

final class NotificationDeliveryService
{
    public function chatMessage(ChatMessage $message): void
    {
        $message->loadMissing(['sender', 'chatRoom.members.user']);
        $senderName = $message->sender->name;
        $room = $message->chatRoom;

        foreach ($room->members as $member) {
            $recipient = $member->user;

            if ($recipient === null
                || $recipient->is($message->sender)
                || ! in_array($recipient->role, [UserRole::Student, UserRole::Coach], true)
                || $recipient->status !== UserStatus::InProgress
                || ! $recipient->can('view', $room)) {
                continue;
            }

            $this->deliver(
                $recipient,
                'chat_message_received',
                "【Certify LMS】{$senderName}さんから新しいチャットメッセージが届きました",
                "{$senderName}さんから新しいチャットメッセージが届きました。以下のリンクから内容をご確認ください。",
                'chat_room',
                $room->id,
                route('chat.show', $room),
                sourceType: 'chat_message',
                sourceId: $message->id,
            );
        }
    }

    public function qaReply(QaReply $reply): void
    {
        $reply->loadMissing(['user', 'thread.certification.coaches', 'thread.user']);
        $thread = $reply->thread;
        $recipient = $thread->user;

        if (! $recipient instanceof User
            || ! $recipient->exists
            || $recipient->is($reply->user)
            || ! $recipient->can('view', $thread)) {
            return;
        }

        $replyAuthorName = $reply->user->name;

        $this->deliver(
            $recipient,
            'qa_reply_received',
            "【Certify LMS】{$replyAuthorName}さんからご質問への回答が届きました",
            "{$replyAuthorName}さんからご質問への回答が届きました。以下のリンクから回答をご確認ください。",
            'qa_thread',
            $thread->id,
            route('qa-board.show', $thread),
            sourceType: 'qa_reply',
            sourceId: $reply->id,
        );
    }

    public function meetingReserved(Meeting $meeting): void
    {
        if ($meeting->status !== MeetingStatus::Reserved) {
            return;
        }

        $meeting->loadMissing(['student', 'coach']);
        $recipient = $meeting->coach;

        if (! $recipient instanceof User || ! $recipient->can('view', $meeting)) {
            return;
        }

        $studentName = $meeting->student->name;

        $this->deliver(
            $recipient,
            'meeting_reserved',
            "【Certify LMS】{$studentName}さんから面談のご予約が入りました",
            "{$studentName}さんとの面談が予約されました。以下のリンクから予約内容をご確認ください。",
            'meeting',
            $meeting->id,
            route('meetings.show', $meeting),
            $meeting,
        );
    }

    public function meetingCanceled(Meeting $meeting, User $actor): void
    {
        if ($meeting->status !== MeetingStatus::Canceled
            || (! $actor->is($meeting->student) && ! $actor->is($meeting->coach))) {
            return;
        }

        $meeting->loadMissing(['student', 'coach']);
        $recipient = $actor->is($meeting->student) ? $meeting->coach : $meeting->student;

        if (! $recipient instanceof User || ! $recipient->can('view', $meeting)) {
            return;
        }

        $actorName = $actor->name;

        $this->deliver(
            $recipient,
            'meeting_canceled',
            "【Certify LMS】{$actorName}さんから面談キャンセルのご連絡がありました",
            "{$actorName}さんとの面談がキャンセルされました。以下のリンクから詳細をご確認ください。",
            'meeting',
            $meeting->id,
            route('meetings.show', $meeting),
            $meeting,
        );
    }

    public function accessibleTarget(User $user, DatabaseNotification $notification): ?string
    {
        $data = $notification->data;
        $targetId = $data['target_id'] ?? null;

        if (! is_string($targetId)) {
            return null;
        }

        if (isset($data['source_type'])) {
            $sourceId = $data['source_id'] ?? null;

            if (! is_string($data['source_type'])
                || ! is_string($sourceId)
                || ! $this->sourceExists($data['source_type'], $sourceId, $targetId)) {
                return null;
            }
        }

        return match ($data['target_type'] ?? null) {
            'chat_room' => $this->chatTarget($user, $targetId),
            'qa_thread' => $this->qaTarget($user, $targetId),
            'meeting' => $this->meetingTarget($user, $targetId),
            default => null,
        };
    }

    private function sourceExists(string $sourceType, string $sourceId, string $targetId): bool
    {
        return match ($sourceType) {
            'chat_message' => ChatMessage::query()
                ->whereKey($sourceId)
                ->where('chat_room_id', $targetId)
                ->exists(),
            'qa_reply' => QaReply::query()
                ->whereKey($sourceId)
                ->where('qa_thread_id', $targetId)
                ->exists(),
            // 面談リマインダーは面談自身を source とするため、source と target が同じ面談であることを確認する
            'meeting' => $sourceId === $targetId
                && Meeting::query()->whereKey($sourceId)->exists(),
            default => false,
        };
    }

    private function chatTarget(User $user, string $targetId): ?string
    {
        if (! in_array($user->role, [UserRole::Student, UserRole::Coach], true)
            || $user->status !== UserStatus::InProgress) {
            return null;
        }

        $room = ChatRoom::query()->find($targetId);

        return $room !== null && $user->can('view', $room)
            ? route('chat.show', $room)
            : null;
    }

    private function qaTarget(User $user, string $targetId): ?string
    {
        $thread = QaThread::query()->with('certification.coaches')->find($targetId);

        return $thread !== null && $user->can('view', $thread)
            ? route('qa-board.show', $thread)
            : null;
    }

    private function meetingTarget(User $user, string $targetId): ?string
    {
        $meeting = Meeting::query()->find($targetId);

        return $meeting !== null && $user->can('view', $meeting)
            ? route('meetings.show', $meeting)
            : null;
    }

    private function deliver(
        User $recipient,
        string $type,
        string $title,
        string $message,
        string $targetType,
        string $targetId,
        string $url,
        ?Meeting $meeting = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): void {
        $greeting = "{$recipient->name}さん、いつもCertify LMSをご利用いただきありがとうございます。";
        $meetingStartAt = $meeting?->scheduled_at
            ->copy()
            ->setTimezone('Asia/Tokyo')
            ->format('Y/m/d H:i');
        $body = implode("\n\n", array_filter([
            $greeting,
            $message,
            $meetingStartAt === null ? null : "面談開始日時：{$meetingStartAt}（日本時間）",
            '内容を確認する：'.$url,
        ]));
        $data = [
            'notification_type' => $type,
            'title' => $title,
            'message' => $message,
            'body' => $body,
            'greeting' => $greeting,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'url' => $url,
        ];

        if ($sourceType !== null && $sourceId !== null) {
            $data['source_type'] = $sourceType;
            $data['source_id'] = $sourceId;
        }

        if ($meetingStartAt !== null) {
            $data['meeting_start_at'] = $meetingStartAt;
        }

        $notification = new BusinessEventNotification($data);
        $notification->id = (string) Str::uuid();
        Notification::sendNow($recipient, $notification, ['database']);

        try {
            Notification::sendNow($recipient, $notification, ['mail']);
        } catch (Throwable $exception) {
            Log::error('Business event notification email delivery failed.', [
                'notification_type' => $type,
                'recipient_id' => $recipient->id,
                'notification_id' => $notification->id,
                'exception' => $exception,
            ]);
        }
    }
}
