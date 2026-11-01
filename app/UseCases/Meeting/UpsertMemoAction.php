<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use Illuminate\Support\Facades\DB;

/**
 * 担当コーチによる面談メモの作成・更新 Action。canceled の面談にはメモを残せない。
 */
final class UpsertMemoAction
{
    /**
     * @throws MeetingStatusTransitionException
     */
    public function __invoke(Meeting $meeting, string $body): void
    {
        DB::transaction(function () use ($meeting, $body) {
            if (! in_array($meeting->status, [MeetingStatus::Reserved, MeetingStatus::Completed], true)) {
                throw MeetingStatusTransitionException::forMemo();
            }

            MeetingMemo::updateOrCreate(
                ['meeting_id' => $meeting->id],
                ['body' => $body],
            );
        });
    }
}
