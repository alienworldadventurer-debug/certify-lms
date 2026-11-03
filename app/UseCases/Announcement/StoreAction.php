<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class StoreAction
{
    /**
     * @param array{title: string, body: string, target_type: string, target_certification_id?: ?string, target_user_id?: ?string} $validated
     */
    public function __invoke(User $admin, array $validated): Announcement
    {
        $announcement = DB::transaction(function () use ($admin, $validated): Announcement {
            $targetType = AnnouncementTargetType::from($validated['target_type']);

            $announcement = Announcement::query()->create([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'target_type' => $targetType,
                'target_certification_id' => $targetType === AnnouncementTargetType::Certification
                    ? $validated['target_certification_id'] ?? null
                    : null,
                'target_user_id' => $targetType === AnnouncementTargetType::User
                    ? $validated['target_user_id'] ?? null
                    : null,
                'dispatched_count' => 0,
                'dispatched_at' => now(),
                'created_by' => $admin->id,
            ]);

            $recipients = iterator_to_array($this->recipients($announcement), false);
            $announcement->update(['dispatched_count' => count($recipients)]);

            // afterCommit が有効な通知はトランザクション内で投入しても、コミット後にキューへ登録される
            foreach ($recipients as $recipient) {
                $notificationId = (string) Str::uuid();
                $notification = new AdminAnnouncementNotification($announcement, $notificationId);
                $notification->id = $notificationId;

                Notification::send($recipient, $notification);
            }

            return $announcement;
        });

        return $announcement;
    }

    /**
     * @return iterable<int, User>
     */
    private function recipients(Announcement $announcement): iterable
    {
        $query = User::query()->announcementRecipients();

        return match ($announcement->target_type) {
            AnnouncementTargetType::AllStudents => $query->get(),
            AnnouncementTargetType::Certification => $query
                ->whereHas('enrollments', fn ($enrollments) => $enrollments
                    ->where('certification_id', $announcement->target_certification_id))
                ->get(),
            AnnouncementTargetType::User => $query
                ->whereKey($announcement->target_user_id)
                ->get(),
        };
    }
}
