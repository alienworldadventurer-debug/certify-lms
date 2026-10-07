<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateAction
{
    /**
     * @param array<string, mixed> $validated
     */
    public function __invoke(MeetingPack $meetingPack, User $admin, array $validated): MeetingPack
    {
        return DB::transaction(function () use ($meetingPack, $admin, $validated): MeetingPack {
            $meetingPack->update([
                ...$validated,
                'sort_order' => $validated['sort_order'] ?? 0,
                'updated_by_user_id' => $admin->id,
            ]);

            return $meetingPack->fresh();
        });
    }
}
