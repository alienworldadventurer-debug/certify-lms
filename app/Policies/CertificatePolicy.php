<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\User;

/**
 * Certificate リソースに対する認可ポリシー。
 *
 * - download: 受講生本人(受講中 / 卒業とも可) / 担当資格のコーチ / admin
 *
 * コーチの担当判定は certification.coaches リレーション(certification_coach_assignments 経由)で行う。
 * 受講生の状態(受講中 / 卒業)は判定しない(修了証は永続資産)。
 */
class CertificatePolicy
{
    public function download(User $user, Certificate $certificate): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Student => $certificate->user_id === $user->id,
            UserRole::Coach => $this->isAssignedCoach($certificate, $user),
        };
    }

    private function isAssignedCoach(Certificate $certificate, User $coach): bool
    {
        $certificate->loadMissing('certification.coaches');

        return $certificate->certification?->coaches->contains('id', $coach->id) ?? false;
    }
}
