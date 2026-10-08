<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Support\Facades\DB;

final class DestroyAction
{
    public function __invoke(MeetingPack $meetingPack): bool
    {
        return DB::transaction(function () use ($meetingPack): bool {
            $lockedPack = MeetingPack::query()
                ->lockForUpdate()
                ->findOrFail($meetingPack->id);

            if ($lockedPack->status === MeetingPackStatus::Published) {
                return false;
            }

            $lockedPack->delete();

            return true;
        });
    }
}
