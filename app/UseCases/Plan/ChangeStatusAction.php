<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ChangeStatusAction
{
    /**
     * @return array{changed: bool, message: string}
     */
    public function __invoke(Plan $plan, User $admin, PlanStatus $targetStatus): array
    {
        return DB::transaction(function () use ($plan, $admin, $targetStatus): array {
            $lockedPlan = Plan::query()
                ->lockForUpdate()
                ->findOrFail($plan->id);

            $transition = match ([$lockedPlan->status, $targetStatus]) {
                [PlanStatus::Draft, PlanStatus::Published] => 'published',
                [PlanStatus::Published, PlanStatus::Archived] => 'archived',
                [PlanStatus::Archived, PlanStatus::Draft] => 'unarchived',
                default => null,
            };

            if ($transition === null) {
                return [
                    'changed' => false,
                    'message' => match ($lockedPlan->status) {
                        PlanStatus::Draft => '下書きのプランは公開のみ可能です。',
                        PlanStatus::Published => '公開中のプランはアーカイブのみ可能です。',
                        PlanStatus::Archived => 'アーカイブ済みのプランは下書きに戻すことのみ可能です。',
                    },
                ];
            }

            $lockedPlan->update([
                'status' => $targetStatus,
                'updated_by_user_id' => $admin->id,
            ]);

            return [
                'changed' => true,
                'message' => match ($transition) {
                    'published' => 'プランを公開しました。',
                    'archived' => 'プランをアーカイブしました。',
                    'unarchived' => 'プランを下書きに戻しました。',
                },
            ];
        });
    }
}
