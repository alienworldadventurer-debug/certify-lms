<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
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

            if (count($events) < 3) {
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
            ->with('members.user')
            ->first();

        if ($room !== null) {
            $sender = $room->members
                ->pluck('user')
                ->first(fn (?User $user): bool => $user !== null
                    && $user->id !== $recipient->id
                    && $user->status === UserStatus::InProgress);

            if ($sender !== null) {
                $events[] = $this->event(
                    $recipient,
                    'chat_message_received',
                    "{$sender->name}さんから新しいチャットメッセージが届きました",
                    "{$sender->name}さんから新しいチャットメッセージが届きました。以下のリンクから内容をご確認ください。",
                    'chat_room',
                    $room->id,
                    route('chat.show', $room),
                );
            }
        }

        $threadQuery = QaThread::query()
            ->whereHas('certification.coaches', fn ($query) => $query->where('users.id', $recipient->id))
            ->with(['certification.coaches', 'replies.user']);

        if ($recipient->role === UserRole::Student) {
            $threadQuery = QaThread::query()->with(['certification.coaches', 'replies.user']);
        }

        $thread = $threadQuery->get()->first(function (QaThread $thread) use ($recipient): bool {
            if (! $recipient->can('view', $thread)) {
                return false;
            }

            return $thread->replies->contains(
                fn ($reply): bool => $reply->user !== null
                    && $reply->user->id !== $recipient->id
                    && $reply->user->status === UserStatus::InProgress,
            );
        });

        if ($thread !== null) {
            $reply = $thread->replies->first(
                fn ($reply): bool => $reply->user !== null
                    && $reply->user->id !== $recipient->id
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
            );
        }

        $meetings = $recipient->role === UserRole::Coach
            ? Meeting::query()->where('coach_id', $recipient->id)->with(['student', 'canceledBy'])->get()
            : Meeting::query()->where('student_id', $recipient->id)->with(['coach', 'canceledBy'])->get();

        $meeting = $meetings->first();

        if ($meeting !== null) {
            $otherParty = $recipient->role === UserRole::Coach ? $meeting->student : $meeting->coach;

            if ($otherParty instanceof User) {
                if ($recipient->role === UserRole::Coach) {
                    $events[] = $this->event(
                        $recipient,
                        'meeting_reserved',
                        "{$meeting->student->name}さんから面談のご予約が入りました",
                        "{$meeting->student->name}さんとの面談が予約されました。以下のリンクから予約内容をご確認ください。",
                        'meeting',
                        $meeting->id,
                        route('meetings.show', $meeting),
                        $meeting,
                    );
                }

                $actorName = $recipient->role === UserRole::Coach ? $meeting->student->name : $meeting->coach->name;
                $events[] = $this->event(
                    $recipient,
                    'meeting_canceled',
                    "{$actorName}さんから面談キャンセルのご連絡がありました",
                    "{$actorName}さんとの面談がキャンセルされました。以下のリンクから詳細をご確認ください。",
                    'meeting',
                    $meeting->id,
                    route('meetings.show', $meeting),
                    $meeting,
                );
            }
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

        if ($meetingStartAt !== null) {
            $data['meeting_start_at'] = $meetingStartAt;
        }

        return $data;
    }
}
