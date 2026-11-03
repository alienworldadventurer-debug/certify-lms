<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\Policies\SectionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SectionPolicy の判定を検証する Unit テスト。
 * admin: 全可 / coach: 担当のみ / student: section + chapter + part すべて Published 必要。
 */
class SectionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_full_access(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->published()->create();
        $policy = new SectionPolicy;

        $this->assertTrue($policy->view($admin, $section));
        $this->assertTrue($policy->update($admin, $section));
        $this->assertTrue($policy->preview($admin, $section));
    }

    public function test_student_view_requires_full_published_chain(): void
    {
        $student = User::factory()->student()->create();
        $publishedPart = Part::factory()->published()->create();
        $publishedChapter = Chapter::factory()->for($publishedPart)->published()->create();
        $publishedSection = Section::factory()->for($publishedChapter)->published()->create();

        $draftPart = Part::factory()->draft()->create();
        $draftChapter = Chapter::factory()->for($draftPart)->published()->create();
        $sectionUnderDraftPart = Section::factory()->for($draftChapter)->published()->create();

        $policy = new SectionPolicy;

        $this->assertTrue($policy->view($student, $publishedSection), 'すべて Published なら閲覧可');
        $this->assertFalse($policy->view($student, $sectionUnderDraftPart), '親階層に draft があれば閲覧不可');
    }

    public function test_coach_can_manage_assigned_certification(): void
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
        $assignedChapter = Chapter::factory()->for($assignedPart)->published()->create();
        $assignedSection = Section::factory()->for($assignedChapter)->draft()->create();

        $otherPart = Part::factory()->for($otherCert)->published()->create();
        $otherChapter = Chapter::factory()->for($otherPart)->published()->create();
        $otherSection = Section::factory()->for($otherChapter)->published()->create();

        $policy = new SectionPolicy;

        // 担当資格に対する全 ability の許可検証（draft の section でも閲覧可能）
        $this->assertTrue($policy->viewAny($coach, $assignedChapter));
        $this->assertTrue($policy->view($coach, $assignedSection), 'coach は担当資格の draft section も閲覧できるはず');
        $this->assertTrue($policy->create($coach, $assignedChapter));
        $this->assertTrue($policy->update($coach, $assignedSection));
        $this->assertTrue($policy->delete($coach, $assignedSection));
        $this->assertTrue($policy->publish($coach, $assignedSection));
        $this->assertTrue($policy->unpublish($coach, $assignedSection));
        $this->assertTrue($policy->reorder($coach, $assignedChapter));
        $this->assertTrue($policy->preview($coach, $assignedSection));

        // 非担当資格に対する全 ability の拒否検証
        $this->assertFalse($policy->viewAny($coach, $otherChapter));
        $this->assertFalse($policy->view($coach, $otherSection));
        $this->assertFalse($policy->create($coach, $otherChapter));
        $this->assertFalse($policy->update($coach, $otherSection));
        $this->assertFalse($policy->delete($coach, $otherSection));
        $this->assertFalse($policy->publish($coach, $otherSection));
        $this->assertFalse($policy->unpublish($coach, $otherSection));
        $this->assertFalse($policy->reorder($coach, $otherChapter));
        $this->assertFalse($policy->preview($coach, $otherSection));
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
        $chapter = Chapter::factory()->for($part)->published()->create();
        $section = Section::factory()->for($chapter)->published()->create();
        $policy = new SectionPolicy;

        $this->assertFalse($policy->viewAny($coach, $chapter));
        $this->assertFalse($policy->view($coach, $section));
        $this->assertFalse($policy->create($coach, $chapter));
        $this->assertFalse($policy->update($coach, $section));
        $this->assertFalse($policy->delete($coach, $section));
        $this->assertFalse($policy->publish($coach, $section));
        $this->assertFalse($policy->unpublish($coach, $section));
        $this->assertFalse($policy->reorder($coach, $chapter));
        $this->assertFalse($policy->preview($coach, $section));
    }
}
