<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Meeting;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\BusinessEventNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['student@certify-lms.test', 'coach@certify-lms.test'] as $email) {
            $recipient = User::query()->where('email', $email)->first();

            if ($recipient === null) {
                $this->command?->warn("NotificationSeeder: {$email} が見つかりません。");

                continue;
            }

            $events = $this->eventsFor($recipient);

            if ($events === []) {
                $this->command?->warn("NotificationSeeder: {$email} の通知対象データが不足しています。");

                continue;
            }

            foreach (range(0, 23) as $index) {
                $event = $events[$index % count($events)];
                $createdAt = now()->subHours($index + 1);

                $recipient->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => BusinessEventNotification::class,
                    'data' => $event,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'read_at' => $index % 3 === 0 ? $createdAt->copy()->addMinutes(5) : null,
                ]);
            }
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function eventsFor(User $recipient): array
    {
        $events = [];
        $room = ChatRoom::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $recipient->id))
            ->get()
            ->first(fn (ChatRoom $room): bool => $recipient->can('view', $room));

        if ($room !== null) {
            $message = ChatMessage::query()
                ->where('chat_room_id', $room->id)
                ->where('sender_user_id', '!=', $recipient->id)
                ->whereHas('sender', fn ($query) => $query
                    ->whereIn('role', [UserRole::Student->value, UserRole::Coach->value])
                    ->where('status', UserStatus::InProgress->value))
                ->whereHas('chatRoom.members', fn ($query) => $query
                    ->whereColumn('chat_members.user_id', 'chat_messages.sender_user_id'))
                ->with('sender')
                ->latest()
                ->first();

            if ($message !== null) {
                $senderName = $message->sender->name;
                $events[] = $this->event(
                    $recipient,
                    'chat_message_received',
                    "{$senderName}さんから新しいチャットメッセージが届きました",
                    "{$senderName}さんから新しいチャットメッセージが届きました。以下のリンクから内容をご確認ください。",
                    'chat_room',
                    $room->id,
                    route('chat.show', $room),
                    sourceType: 'chat_message',
                    sourceId: $message->id,
                );
            }
        }

        $thread = $recipient->role === UserRole::Student
            ? QaThread::query()
                ->where('user_id', $recipient->id)
                ->with(['certification.coaches', 'replies.user'])
                ->get()
                ->first(fn (QaThread $thread): bool => $recipient->can('view', $thread)
                    && $thread->replies->contains(fn ($reply): bool => $reply->user !== null
                        && $reply->user->id !== $recipient->id
                        && in_array($reply->user->role, [UserRole::Student, UserRole::Coach], true)
                        && $reply->user->status === UserStatus::InProgress))
            : null;

        if ($thread !== null) {
            $reply = $thread->replies->first(
                fn ($reply): bool => $reply->user !== null
                    && $reply->user->id !== $recipient->id
                    && in_array($reply->user->role, [UserRole::Student, UserRole::Coach], true)
                    && $reply->user->status === UserStatus::InProgress,
            );
            $authorName = $reply->user->name;
            $events[] = $this->event(
                $recipient,
                'qa_reply_received',
                "{$authorName}さんからご質問への回答が届きました",
                "{$authorName}さんからご質問への回答が届きました。以下のリンクから回答をご確認ください。",
                'qa_thread',
                $thread->id,
                route('qa-board.show', $thread),
                sourceType: 'qa_reply',
                sourceId: $reply->id,
            );
        }

        $meetingQuery = Meeting::query()
            ->with(['student', 'coach', 'canceledBy'])
            ->when(
                $recipient->role === UserRole::Coach,
                fn ($query) => $query->where('coach_id', $recipient->id),
                fn ($query) => $query->where('student_id', $recipient->id),
            );
        $meetings = $meetingQuery->get();

        if ($recipient->role === UserRole::Coach) {
            $meeting = $meetings->first(fn (Meeting $meeting): bool => $meeting->status === MeetingStatus::Reserved);

            if ($meeting !== null && $meeting->student instanceof User) {
                $studentName = $meeting->student->name;
                $events[] = $this->event(
                    $recipient,
                    'meeting_reserved',
                    "{$studentName}さんから面談のご予約が入りました",
                    "{$studentName}さんとの面談が予約されました。以下のリンクから予約内容をご確認ください。",
                    'meeting',
                    $meeting->id,
                    route('meetings.show', $meeting),
                    $meeting,
                );
            }
        }

        $canceledMeeting = $meetings->first(function (Meeting $meeting) use ($recipient): bool {
            if ($meeting->status !== MeetingStatus::Canceled || ! $meeting->canceledBy instanceof User) {
                return false;
            }

            return $recipient->role === UserRole::Coach
                ? $meeting->canceledBy->is($meeting->student)
                : $meeting->canceledBy->is($meeting->coach);
        });

        if ($canceledMeeting !== null && $canceledMeeting->canceledBy instanceof User) {
            $actorName = $canceledMeeting->canceledBy->name;
            $events[] = $this->event(
                $recipient,
                'meeting_canceled',
                "{$actorName}さんから面談キャンセルのご連絡がありました",
                "{$actorName}さんとの面談がキャンセルされました。以下のリンクから詳細をご確認ください。",
                'meeting',
                $canceledMeeting->id,
                route('meetings.show', $canceledMeeting),
                $canceledMeeting,
            );
        }

        return $events;
    }

    /**
     * @return array<string, string>
     */
    private function event(
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
    ): array {
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
            'title' => "【Certify LMS】{$title}",
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

        return $data;
    }
}
