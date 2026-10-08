<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ChangeStatusAction
{
    /**
     * @return array{changed: bool, message: string}
     */
    public function __invoke(MeetingPack $meetingPack, User $admin, MeetingPackStatus $targetStatus): array
    {
        return DB::transaction(function () use ($meetingPack, $admin, $targetStatus): array {
            $lockedPack = MeetingPack::query()
                ->lockForUpdate()
                ->findOrFail($meetingPack->id);

            $transition = match ([$lockedPack->status, $targetStatus]) {
                [MeetingPackStatus::Draft, MeetingPackStatus::Published] => 'published',
                [MeetingPackStatus::Published, MeetingPackStatus::Archived] => 'archived',
                [MeetingPackStatus::Archived, MeetingPackStatus::Draft] => 'unarchived',
                default => null,
            };

            if ($transition === null) {
                return [
                    'changed' => false,
                    'message' => match ($lockedPack->status) {
                        MeetingPackStatus::Draft => '下書きの面談パックは公開のみ可能です。',
                        MeetingPackStatus::Published => '公開中の面談パックはアーカイブのみ可能です。',
                        MeetingPackStatus::Archived => 'アーカイブ済みの面談パックは下書きに戻すことのみ可能です。',
                    },
                ];
            }

            $lockedPack->update([
                'status' => $targetStatus,
                'updated_by_user_id' => $admin->id,
            ]);

            return [
                'changed' => true,
                'message' => match ($transition) {
                    'published' => '面談パックを公開しました。',
                    'archived' => '面談パックをアーカイブしました。',
                    'unarchived' => '面談パックを下書きに戻しました。',
                },
            ];
        });
    }
}
