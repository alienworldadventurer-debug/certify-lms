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
            $meetingPack->fill([
                ...$validated,
                'sort_order' => $validated['sort_order'] ?? $meetingPack->sort_order,
                'updated_by_user_id' => $admin->id,
            ]);
            $meetingPack->setUpdatedAt(now());
            $meetingPack->save();

            return $meetingPack->fresh();
        });
    }
}
