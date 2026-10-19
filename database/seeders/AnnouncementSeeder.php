<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@certify-lms.test')->first();

        if ($admin === null || Announcement::query()->exists()) {
            return;
        }

        $certification = Certification::query()->orderBy('id')->first();
        $student = User::query()->where('email', 'student@certify-lms.test')->first();

        $this->dispatch($admin, [
            'title' => '【全体】システムメンテナンスのお知らせ',
            'body' => "平素よりCertify LMSをご利用いただきありがとうございます。\n下記日時にメンテナンスを実施します。",
            'target_type' => AnnouncementTargetType::AllStudents,
        ]);

        if ($certification !== null) {
            $this->dispatch($admin, [
                'title' => '【資格】学習教材を更新しました',
                'body' => '対象資格の学習教材を更新しました。学習計画をご確認ください。',
                'target_type' => AnnouncementTargetType::Certification,
                'target_certification_id' => $certification->id,
            ]);
        }

        if ($student !== null) {
            $this->dispatch($admin, [
                'title' => '【個別】面談日程についてのご案内',
                'body' => '面談日程の候補を送付しました。ご確認をお願いします。',
                'target_type' => AnnouncementTargetType::User,
                'target_user_id' => $student->id,
            ]);
        }
    }

    /**
     * 通知件数と dispatched_count を一致させるため、実配信と同じ件数計算を行う。
     *
     * @param array{title: string, body: string, target_type: AnnouncementTargetType, target_certification_id?: int, target_user_id?: int} $attributes
     */
    private function dispatch(User $admin, array $attributes): void
    {
        $recipients = $this->recipientsFor($attributes);

        $announcement = Announcement::query()->create([
            ...$attributes,
            'target_type' => $attributes['target_type']->value,
            'dispatched_count' => $recipients->count(),
            'dispatched_at' => now(),
            'created_by' => $admin->id,
        ]);

        foreach ($recipients as $recipient) {
            $notification = new AdminAnnouncementNotification($announcement, (string) Str::uuid());
            Notification::sendNow($recipient, $notification, ['database']);
        }
    }

    private function recipientsFor(array $attributes)
    {
        $query = User::query()
            ->where('role', UserRole::Student)
            ->where('status', UserStatus::InProgress);

        return match ($attributes['target_type']) {
            AnnouncementTargetType::AllStudents => $query->get(),
            AnnouncementTargetType::Certification => $query
                ->whereHas('enrollments', fn ($q) => $q->where('certification_id', $attributes['target_certification_id']))
                ->get(),
            AnnouncementTargetType::User => $query->whereKey($attributes['target_user_id'])->get(),
        };
    }
}
