<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Plan;
use App\Models\User;
use App\Services\MeetingQuotaService;

final class ShowAction
{
    public function __construct(private readonly MeetingQuotaService $quotaService) {}

    public function __invoke(Plan $plan): Plan
    {
        $plan->load([
            'createdBy',
            'updatedBy',
            'users' => fn ($query) => $query
                ->where('role', UserRole::Student)
                ->where('status', UserStatus::InProgress)
                ->orderBy('name'),
        ]);

        $remainingMeetingQuotas = $this->quotaService->remainingForUsers($plan->users);
        // Blade変更禁止のため、永続化用モデルを変えず表示専用コピーに残数を設定する。
        $plan->setRelation('users', $plan->users->map(function (User $user) use ($remainingMeetingQuotas): User {
            $displayUser = clone $user;
            $displayUser->setAttribute('max_meetings', $remainingMeetingQuotas[$user->id]);

            return $displayUser;
        }));

        return $plan;
    }
}
