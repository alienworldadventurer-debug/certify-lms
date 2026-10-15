<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 受講生メモの動作確認用データを投入する Seeder。
 *
 * 固定アカウント(coach / coach2 / admin)の担当関係に応じて、
 * 複数作成者が混在する受講登録、担当外の受講登録(閲覧不可の確認用)を用意する。
 * 再実行時に重複投入しないよう、受講登録ごとに既存メモが無い場合のみ作成する。
 */
final class EnrollmentNoteSeeder extends Seeder
{
    public function run(): void
    {
        $coach1 = User::query()->where('email', 'coach@certify-lms.test')->first();
        $coach2 = User::query()->where('email', 'coach2@certify-lms.test')->first();
        $admin = User::query()->where('email', 'admin@certify-lms.test')->first();

        if ($coach1 === null || $coach2 === null || $admin === null) {
            $this->command?->warn('EnrollmentNoteSeeder: 固定アカウントが存在しません。スキップします。');

            return;
        }

        $coach1CertificationIds = $this->assignedCertificationIds($coach1);
        $coach2CertificationIds = $this->assignedCertificationIds($coach2);

        $sharedEnrollment = Enrollment::query()
            ->whereIn('certification_id', array_intersect($coach1CertificationIds, $coach2CertificationIds))
            ->orderBy('created_at')
            ->first();

        $coach1Enrollment = Enrollment::query()
            ->whereIn('certification_id', array_diff($coach1CertificationIds, $coach2CertificationIds))
            ->orderBy('created_at')
            ->first();

        $unassignedEnrollment = Enrollment::query()
            ->whereNotIn('certification_id', array_merge($coach1CertificationIds, $coach2CertificationIds))
            ->orderBy('created_at')
            ->first();

        if ($sharedEnrollment !== null) {
            $this->seedNotes($sharedEnrollment, [
                [$coach1, '初回面談で学習計画を確認。平日夜に学習時間を確保できると回答。'],
                [$coach2, '過去問の正答率が低い分野があるため、重点的に復習するよう案内。'],
                [$admin, '受講料の支払い状況を確認済み。'],
            ]);
        }

        if ($coach1Enrollment !== null) {
            $this->seedNotes($coach1Enrollment, [
                [$coach1, '模擬試験の結果を共有。次回面談までに弱点分野の演習を依頼。'],
            ]);
        }

        if ($unassignedEnrollment !== null) {
            $this->seedNotes($unassignedEnrollment, [
                [$admin, '担当コーチ未割当の受講登録。担当コーチ割当後に確認が必要。'],
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function assignedCertificationIds(User $coach): array
    {
        return CertificationCoachAssignment::query()
            ->where('user_id', $coach->id)
            ->whereNull('unassigned_at')
            ->pluck('certification_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * @param array<int, array{0: User, 1: string}> $notes
     */
    private function seedNotes(Enrollment $enrollment, array $notes): void
    {
        if (EnrollmentNote::query()->where('enrollment_id', $enrollment->id)->exists()) {
            return;
        }

        foreach ($notes as [$author, $body]) {
            EnrollmentNote::query()->create([
                'enrollment_id' => $enrollment->id,
                'author_id' => $author->id,
                'body' => $body,
            ]);
        }
    }
}
