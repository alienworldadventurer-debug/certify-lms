<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * 面談リマインダーを window 単位で配信するユースケース。
 *
 * - `eve`: 翌日（JST）開始の reserved 面談
 * - `one_hour_before`: 現在から 60 分以内に開始する reserved 面談（開始済みは除外）
 *
 * 受信者ごとに DB トランザクションを張り、面談行をロックして状態と重複（同一面談・同一 window の
 * 既存通知）を再確認してから、アプリ内通知とメールを送る。メール失敗時は同じトランザクションの
 * 通知保存もロールバックされ、次回実行で再試行される。
 */
final class SendMeetingReminderAction
{
    private const JAPANESE_WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    /**
     * @return array{delivered: int, skipped: int, failed: int}
     */
    public function __invoke(MeetingReminderWindow $window): array
    {
        $counts = ['delivered' => 0, 'skipped' => 0, 'failed' => 0];

        $this->targetQuery($window)
            ->orderBy('id')
            ->chunkById(100, function ($meetings) use ($window, &$counts): void {
                foreach ($meetings as $meeting) {
                    foreach ($this->recipientsOf($meeting) as $recipient) {
                        $result = $this->deliverTo($meeting->id, $recipient->id, $window);
                        $counts[$result]++;
                    }
                }
            });

        return $counts;
    }

    private function targetQuery(MeetingReminderWindow $window): Builder
    {
        $now = CarbonImmutable::now('Asia/Tokyo');
        $query = Meeting::query()->where('status', MeetingStatus::Reserved->value);

        return match ($window) {
            MeetingReminderWindow::Eve => $query->whereBetween('scheduled_at', [
                $now->addDay()->startOfDay(),
                $now->addDay()->endOfDay(),
            ]),
            MeetingReminderWindow::OneHourBefore => $query->where('scheduled_at', '>', $now)
                ->where('scheduled_at', '<=', $now->addMinutes(60)),
        };
    }

    /**
     * @return list<User>
     */
    private function recipientsOf(Meeting $meeting): array
    {
        $meeting->loadMissing([
            'student' => fn ($query) => $query->withTrashed(),
            'coach' => fn ($query) => $query->withTrashed(),
        ]);

        return array_values(array_filter(
            [$meeting->student, $meeting->coach],
            fn ($user) => $user instanceof User && $this->canReceive($user),
        ));
    }

    private function canReceive(User $user): bool
    {
        return ! $user->trashed()
            && ! in_array($user->status, [UserStatus::Invited, UserStatus::Withdrawn], true);
    }

    /**
     * @return 'delivered'|'skipped'|'failed'
     */
    private function deliverTo(int|string $meetingId, int|string $recipientId, MeetingReminderWindow $window): string
    {
        try {
            return DB::transaction(function () use ($meetingId, $recipientId, $window): string {
                $meeting = Meeting::query()->whereKey($meetingId)->lockForUpdate()->first();
                $recipient = User::query()->whereKey($recipientId)->first();

                if ($meeting === null
                    || $recipient === null
                    || ! $this->canReceive($recipient)
                    || $meeting->status !== MeetingStatus::Reserved
                    || $meeting->scheduled_at->isPast()) {
                    return 'skipped';
                }

                $alreadySent = $recipient->notifications()
                    ->where('data', 'like', '%"notification_type":"meeting_reminder"%')
                    ->where('data', 'like', '%"source_type":"meeting"%')
                    ->where('data', 'like', '%"source_id":"'.$meeting->id.'"%')
                    ->where('data', 'like', '%"reminder_window":"'.$window->value.'"%')
                    ->exists();

                if ($alreadySent) {
                    return 'skipped';
                }

                $notification = $this->buildNotification($meeting, $recipient, $window);
                Notification::sendNow($recipient, $notification, ['database', 'mail']);

                return 'delivered';
            });
        } catch (Throwable $exception) {
            Log::error('Meeting reminder delivery failed.', [
                'meeting_id' => $meetingId,
                'recipient_id' => $recipientId,
                'reminder_window' => $window->value,
                'exception' => $exception,
            ]);

            return 'failed';
        }
    }

    private function buildNotification(Meeting $meeting, User $recipient, MeetingReminderWindow $window): MeetingReminderNotification
    {
        $meeting->loadMissing([
            'student' => fn ($query) => $query->withTrashed(),
            'coach' => fn ($query) => $query->withTrashed(),
            'enrollment.certification',
        ]);

        $isStudent = $recipient->is($meeting->student);
        $counterpart = $isStudent ? $meeting->coach : $meeting->student;
        $counterpartLabel = $isStudent ? $counterpart->name.'コーチ' : $counterpart->name.'様';

        $start = $meeting->scheduled_at->copy()->setTimezone('Asia/Tokyo');
        $scheduleLabel = $start->format('n月j日').'('.self::JAPANESE_WEEKDAYS[$start->dayOfWeek].')'
            .' '.$start->format('H:i').'〜'.$start->copy()->addMinutes(60)->format('H:i');

        $certificationName = $meeting->enrollment?->certification?->name ?? '';
        $topic = (string) $meeting->topic;

        $data = [
            'title' => '【Certify LMS】'.$window->label(),
            'message' => $start->format('Y/m/d H:i').'に予定されている'.$counterpartLabel.'との面談',
            'body' => implode("\n", [
                '日時：'.$scheduleLabel,
                '資格：'.$certificationName,
                '相談内容：'.$topic,
            ]),
            'schedule_label' => $scheduleLabel,
            'certification_name' => $certificationName,
            'topic' => $topic,
            'counterpart_label' => $counterpartLabel,
            'target_id' => (string) $meeting->id,
            'url' => route('meetings.show', $meeting),
            'reminder_window' => $window->value,
            'source_id' => (string) $meeting->id,
        ];

        return new MeetingReminderNotification($data, $window);
    }
}
