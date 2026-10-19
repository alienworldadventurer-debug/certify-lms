<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

final class StoreAction
{
    /**
     * @param array{title: string, body: string, target_type: string, target_certification_id?: ?string, target_user_id?: ?string} $validated
     */
    public function __invoke(User $admin, array $validated): Announcement
    {
        $pending = [];

        $announcement = DB::transaction(function () use ($admin, $validated, &$pending): Announcement {
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

            foreach ($this->recipients($announcement) as $recipient) {
                $notificationId = (string) Str::uuid();
                $notification = new AdminAnnouncementNotification($announcement, $notificationId);
                $notification->id = $notificationId;

                Notification::sendNow($recipient, $notification, ['database']);
                $pending[] = [$recipient, $notification, $notificationId];
            }

            $announcement->update(['dispatched_count' => count($pending)]);

            return $announcement;
        });

        // メールは DB コミット後に送信する（ロールバック時に存在しない通知を指すメールを残さないため）
        foreach ($pending as [$recipient, $notification, $notificationId]) {
            try {
                Notification::sendNow($recipient, $notification, ['mail']);
            } catch (Throwable $exception) {
                Log::error('Admin announcement email delivery failed.', [
                    'announcement_id' => $announcement->id,
                    'recipient_id' => $recipient->id,
                    'notification_id' => $notificationId,
                    'exception' => $exception,
                ]);
            }
        }

        return $announcement;
    }

    /**
     * @return iterable<int, User>
     */
    private function recipients(Announcement $announcement): iterable
    {
        $query = User::query()
            ->where('role', UserRole::Student)
            ->where('status', UserStatus::InProgress);

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
