<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StoreAction
{
    /**
     * @param array<string, mixed> $validated
     */
    public function __invoke(User $admin, array $validated): MeetingPack
    {
        return DB::transaction(fn () => MeetingPack::query()->create([
            ...$validated,
            'sort_order' => $validated['sort_order'] ?? 0,
            'status' => MeetingPackStatus::Draft,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]));
    }
}
