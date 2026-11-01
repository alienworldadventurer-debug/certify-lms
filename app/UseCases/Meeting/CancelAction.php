<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\NotificationDeliveryService;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;

/**
 * 面談キャンセル Action。reserved かつ開始前のみキャンセル可。消費済の面談回数 1 回分を返却する。
 *
 * - 状態遷移・面談回数の返却はトランザクション内で行う
 * - キャンセル通知と Google カレンダーの予定削除は `DB::afterCommit()` に登録し、コミット後に実行する(失敗しても取消は巻き戻さない)
 */
final class CancelAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundAction,
        private readonly NotificationDeliveryService $notifications,
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    /**
     * @throws MeetingStatusTransitionException
     * @throws MeetingAlreadyStartedException
     */
    public function __invoke(Meeting $meeting, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $actor) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            ($this->refundAction)($locked->student, $locked->id);

            $canceled = $locked->fresh();

            DB::afterCommit(function () use ($canceled, $actor): void {
                $this->notifications->meetingCanceled($canceled, $actor);
                $this->googleCalendar->deleteMeetingEvent($canceled);
            });

            return $canceled;
        });
    }
}
