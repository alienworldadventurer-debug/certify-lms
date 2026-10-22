<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GoogleCredentialStatus;
use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 担当コーチ集合の面談可能時間枠を 60 分単位で展開し、空きスロットを集計する Service。
 *
 * 受講生の予約画面が「該当資格の担当コーチ全員の有効枠 Union」を 1 日単位で取得し、
 * 既存予約済時刻 を除外して各スロットの「予約可能なコーチ数」を返す。受講生にコーチ個別は提示せず、
 * 予約確定時にコーチを自動割当する。
 */
final class MeetingAvailabilityService
{
    public function __construct(
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    /**
     * 指定 Certification の担当コーチ集合について、指定日 1 日分の 60 分単位空きスロットを返す。
     *
     * 1 リクエストあたり availability 1 クエリ + meetings 1 クエリ で完結させる。
     *
     * @return Collection<int, array{slot_start: Carbon, slot_end: Carbon, available_coach_count: int}>
     */
    public function slotsForCertification(Certification $certification, Carbon $date): Collection
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();
        $dayOfWeek = $date->dayOfWeek;

        $coaches = $certification->coaches()->with('googleCredential')->get();
        if ($coaches->isEmpty()) {
            return collect();
        }

        $coachIds = $coaches->pluck('id')->all();
        $busyByCoach = $this->busyRangesByCoach($coaches, $dayStart, $dayEnd);

        $availabilities = CoachAvailability::query()
            ->whereIn('coach_id', $coachIds)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get();

        $existingMeetings = Meeting::query()
            ->whereIn('coach_id', $coachIds)
            ->whereBetween('scheduled_at', [$dayStart, $dayEnd])
            ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value])
            ->get(['coach_id', 'scheduled_at']);

        // 予約済スロットを (coach_id => Set<H:i>) で索引化
        $bookedByCoach = $existingMeetings
            ->groupBy('coach_id')
            ->map(fn ($rows) => $rows->map(fn (Meeting $m) => $m->scheduled_at->format('H:i'))->all());

        /** @var array<string, int> $slotCounts スロット開始時刻(H:i) → available coach 数 */
        $slotCounts = [];

        foreach ($availabilities as $availability) {
            $slot = Carbon::parse($date->format('Y-m-d').' '.$availability->start_time);
            $end = Carbon::parse($date->format('Y-m-d').' '.$availability->end_time);

            while ($slot->copy()->addHour() <= $end) {
                $slotKey = $slot->format('H:i');
                $coachId = $availability->coach_id;
                $booked = $bookedByCoach[$coachId] ?? [];
                $busy = $this->isBusy($busyByCoach[$coachId] ?? [], $slot);

                if (! in_array($slotKey, $booked, true) && ! $busy) {
                    $slotCounts[$slotKey] = ($slotCounts[$slotKey] ?? 0) + 1;
                }

                $slot->addHour();
            }
        }

        ksort($slotCounts);

        return collect($slotCounts)->map(function (int $count, string $time) use ($date) {
            $start = Carbon::parse($date->format('Y-m-d').' '.$time);

            return [
                'slot_start' => $start,
                'slot_end' => $start->copy()->addHour(),
                'available_coach_count' => $count,
            ];
        })->values();
    }

    /**
     * 連携中コーチの Google busy 区間を取得する。未連携・エラー状態のコーチは対象外。
     *
     * @param Collection<int, User> $coaches
     *
     * @return array<string, array<int, array{start: Carbon, end: Carbon}>>
     */
    private function busyRangesByCoach(Collection $coaches, Carbon $dayStart, Carbon $dayEnd): array
    {
        $busyByCoach = [];

        foreach ($coaches as $coach) {
            $credential = $coach->googleCredential;
            if ($credential === null || $credential->status !== GoogleCredentialStatus::Active) {
                continue;
            }

            $busyByCoach[$coach->id] = $this->googleCalendar->busyRanges($credential, $dayStart, $dayEnd);
        }

        return $busyByCoach;
    }

    /**
     * 指定コーチが枠 [slot, slot+1h) に Google busy 区間を持つかを返す（連携中のみ判定）。
     */
    public function isCoachBusyAt(User $coach, Carbon $slot): bool
    {
        $credential = $coach->googleCredential()->first();
        if ($credential === null || $credential->status !== GoogleCredentialStatus::Active) {
            return false;
        }

        $ranges = $this->googleCalendar->busyRanges($credential, $slot->copy(), $slot->copy()->addHour());

        return $this->isBusy($ranges, $slot);
    }

    /**
     * 1 時間枠 [slot, slot+1h) が busy 区間と重なるかを判定する。
     *
     * @param array<int, array{start: Carbon, end: Carbon}> $ranges
     */
    private function isBusy(array $ranges, Carbon $slot): bool
    {
        $slotEnd = $slot->copy()->addHour();

        foreach ($ranges as $range) {
            if ($range['start']->lt($slotEnd) && $range['end']->gt($slot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 指定 scheduled_at が certification 担当コーチ集合の有効枠内かを検証する。
     * 枠外なら MeetingOutOfAvailabilityException を throw する。
     *
     * @throws MeetingOutOfAvailabilityException
     */
    public function validateSlot(Certification $certification, Carbon $scheduledAt): void
    {
        $slots = $this->slotsForCertification($certification, $scheduledAt->copy()->startOfDay());

        $matched = $slots->contains(
            fn (array $slot) => $slot['slot_start']->equalTo($scheduledAt) && $slot['available_coach_count'] > 0,
        );

        if (! $matched) {
            throw new MeetingOutOfAvailabilityException;
        }
    }
}
