<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\CoachMeetingLoadService;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\Services\NotificationDeliveryService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 受講生の面談予約 Action。残面談回数を確認し、空き枠から過去実績最少のコーチを自動割当して reserved で確定する。
 *
 * - 候補コーチの Google カレンダー空き確認はトランザクションの外で行う(外部 API の遅延で行ロックを保持しないため)
 * - 同時刻の二重予約は、候補コーチ行と同時刻の予約行をトランザクション内でロックして再確認し、直列化する
 * - 面談回数の消費・予約作成はトランザクション内で行う
 * - 通知発火と Google カレンダー登録は `DB::afterCommit()` に登録し、コミット後に実行する(失敗しても予約は巻き戻さない)
 */
final class StoreAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly CoachMeetingLoadService $coachLoadService,
        private readonly MeetingQuotaService $quotaService,
        private readonly ConsumeQuotaAction $consumeAction,
        private readonly NotificationDeliveryService $notifications,
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    /**
     * @throws InsufficientMeetingQuotaException
     * @throws MeetingNoAvailableCoachException
     */
    public function __invoke(Enrollment $enrollment, Carbon $scheduledAt, string $topic): Meeting
    {
        $student = $enrollment->user;

        if ($this->quotaService->remaining($student) < 1) {
            throw new InsufficientMeetingQuotaException;
        }

        $this->availabilityService->validateSlot($enrollment->certification, $scheduledAt);

        $candidates = $this->rejectBusyCoaches(
            $this->findAvailableCoaches($enrollment->certification, $scheduledAt),
            $scheduledAt,
        );
        if ($candidates->isEmpty()) {
            throw new MeetingNoAvailableCoachException;
        }

        return DB::transaction(function () use ($enrollment, $student, $scheduledAt, $topic, $candidates) {
            if ($this->quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $lockedCandidates = User::query()
                ->whereIn('id', $candidates->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $bookedCoachIds = Meeting::query()
                ->where('status', MeetingStatus::Reserved->value)
                ->where('scheduled_at', $scheduledAt)
                ->whereIn('coach_id', $lockedCandidates->pluck('id'))
                ->lockForUpdate()
                ->pluck('coach_id');
            $available = $lockedCandidates->reject(fn (User $c) => $bookedCoachIds->contains($c->id))->values();
            if ($available->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $coach = $this->coachLoadService->leastLoadedCoach($available);

            $meeting = Meeting::create([
                'enrollment_id' => $enrollment->id,
                'coach_id' => $coach->id,
                'student_id' => $student->id,
                'scheduled_at' => $scheduledAt,
                'status' => MeetingStatus::Reserved->value,
                'topic' => $topic,
                'meeting_url_snapshot' => $coach->meeting_url,
            ]);

            $transaction = ($this->consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

            $meeting = $meeting->fresh();

            DB::afterCommit(function () use ($meeting): void {
                $this->notifications->meetingReserved($meeting);
                $this->googleCalendar->createMeetingEvent($meeting);
            });

            return $meeting;
        });
    }

    /**
     * 担当コーチのうち、(1) 当該時刻に有効な availability 枠があり、
     * (2) 当該時刻に reserved / completed の Meeting を持たないコーチを返す（DB のみ）。
     *
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
            ->with('googleCredential')
            ->whereHas('coachAvailabilities', function ($q) use ($scheduledAt, $time) {
                $q->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt) {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->get()
            ->values();
    }

    /**
     * Google カレンダー上で busy なコーチを候補から除外する（外部 API 呼び出しを含む）。
     *
     * @param Collection<int, User> $coaches
     *
     * @return Collection<int, User>
     */
    private function rejectBusyCoaches(Collection $coaches, Carbon $scheduledAt): Collection
    {
        return $coaches
            ->reject(fn (User $coach) => $this->availabilityService->isCoachBusyAt($coach, $scheduledAt))
            ->values();
    }
}
