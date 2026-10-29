<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\User;
use App\Policies\CertificatePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CertificatePolicyTest extends TestCase
{
    use RefreshDatabase;

    private CertificatePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CertificatePolicy;
    }

    public function test_admin_can_download_any_certificate(): void
    {
        $certificate = Certificate::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue($this->policy->download($admin, $certificate));
    }

    public function test_owner_student_can_download_own_certificate_regardless_of_status(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $certificate = Certificate::factory()->create(['user_id' => $student->id]);

        $this->assertTrue($this->policy->download($student, $certificate));
    }

    public function test_owner_student_can_download_own_certificate_while_in_progress(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certificate = Certificate::factory()->create(['user_id' => $student->id]);

        $this->assertTrue($this->policy->download($student, $certificate));
    }

    public function test_student_cannot_download_other_student_certificate(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certificate = Certificate::factory()->create();

        $this->assertFalse($this->policy->download($student, $certificate));
    }

    public function test_assigned_coach_can_download_certificate_of_assigned_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        $certificate = Certificate::factory()->create(['certification_id' => $certification->id]);

        $this->assertTrue($this->policy->download($coach, $certificate));
    }

    public function test_unassigned_coach_cannot_download_certificate(): void
    {
        $coach = User::factory()->coach()->create();
        $certificate = Certificate::factory()->create();

        $this->assertFalse($this->policy->download($coach, $certificate));
    }
}
