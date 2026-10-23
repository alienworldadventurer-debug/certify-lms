<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GoogleCredentialStatus;
use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * コーチ太郎を連携中、コーチ花子を未連携にする。トークンはダミーで、実際の Google API は成功しない。
 */
class GoogleCredentialSeeder extends Seeder
{
    public function run(): void
    {
        $coach = User::query()->where('email', 'coach@certify-lms.test')->firstOrFail();

        GoogleCredential::query()->updateOrCreate(
            ['user_id' => $coach->id],
            [
                'access_token' => 'dummy-access-token',
                'refresh_token' => 'dummy-refresh-token',
                'expires_at' => now()->addHour(),
                'calendar_id' => 'coach@certify-lms.test',
                'status' => GoogleCredentialStatus::Active->value,
                'connected_at' => now(),
            ],
        );
    }
}
