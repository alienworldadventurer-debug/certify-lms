<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Part;
use App\Models\User;
use App\Policies\PartPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PartPolicy の判定を検証する Unit テスト。
 * admin / coach 担当 / student published のロール分岐を網羅する。
 */
class PartPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_full_access(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->for($cert)->published()->create();
        $policy = new PartPolicy;

        $this->assertTrue($policy->viewAny($admin, $cert));
        $this->assertTrue($policy->view($admin, $part));
        $this->assertTrue($policy->update($admin, $part));
        $this->assertTrue($policy->delete($admin, $part));
    }

    public function test_coach_assigned_only(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $assignedCert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $assignedCert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        $assignedPart = Part::factory()->for($assignedCert)->published()->create();
        $otherPart = Part::factory()->for($otherCert)->published()->create();
        $policy = new PartPolicy;

        // 担当資格に対する全 ability の許可検証
        $this->assertTrue($policy->viewAny($coach, $assignedCert));
        $this->assertTrue($policy->view($coach, $assignedPart));
        $this->assertTrue($policy->create($coach, $assignedCert));
        $this->assertTrue($policy->update($coach, $assignedPart));
        $this->assertTrue($policy->delete($coach, $assignedPart));
        $this->assertTrue($policy->publish($coach, $assignedPart));
        $this->assertTrue($policy->unpublish($coach, $assignedPart));
        $this->assertTrue($policy->reorder($coach, $assignedCert));

        // 非担当資格に対する全 ability の拒否検証
        $this->assertFalse($policy->viewAny($coach, $otherCert));
        $this->assertFalse($policy->view($coach, $otherPart));
        $this->assertFalse($policy->create($coach, $otherCert));
        $this->assertFalse($policy->update($coach, $otherPart));
        $this->assertFalse($policy->delete($coach, $otherPart));
        $this->assertFalse($policy->publish($coach, $otherPart));
        $this->assertFalse($policy->unpublish($coach, $otherPart));
        $this->assertFalse($policy->reorder($coach, $otherCert));
    }

    public function test_coach_unassigned_cannot_manage(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $cert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now()->subMonth(),
            'unassigned_at' => now()->subDay(),
        ]);
        $part = Part::factory()->for($cert)->published()->create();
        $policy = new PartPolicy;

        $this->assertFalse($policy->viewAny($coach, $cert));
        $this->assertFalse($policy->view($coach, $part));
        $this->assertFalse($policy->create($coach, $cert));
        $this->assertFalse($policy->update($coach, $part));
        $this->assertFalse($policy->delete($coach, $part));
        $this->assertFalse($policy->publish($coach, $part));
        $this->assertFalse($policy->unpublish($coach, $part));
        $this->assertFalse($policy->reorder($coach, $cert));
    }

    public function test_student_view_published_only(): void
    {
        $student = User::factory()->student()->create();
        $publishedPart = Part::factory()->published()->create();
        $draftPart = Part::factory()->draft()->create();
        $policy = new PartPolicy;

        $this->assertTrue($policy->view($student, $publishedPart));
        $this->assertFalse($policy->view($student, $draftPart));
        $this->assertFalse($policy->update($student, $publishedPart));
    }
}
