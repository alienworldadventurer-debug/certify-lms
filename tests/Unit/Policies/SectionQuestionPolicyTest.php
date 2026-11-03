<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionQuestion;
use App\Models\User;
use App\Policies\SectionQuestionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SectionQuestionPolicy の判定を検証する Unit テスト。
 * admin / coach 担当 / student 受講中 + Published のロール分岐を網羅する。
 */
class SectionQuestionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_full_access(): void
    {
        $admin = User::factory()->admin()->create();
        $question = SectionQuestion::factory()->published()->create();
        $section = $question->section;
        $policy = new SectionQuestionPolicy;

        $this->assertTrue($policy->viewAny($admin, $section));
        $this->assertTrue($policy->view($admin, $question));
        $this->assertTrue($policy->update($admin, $question));
    }

    public function test_student_with_enrollment_can_view_published_question(): void
    {
        $student = User::factory()->student()->create();
        $question = SectionQuestion::factory()->published()->create();
        $cert = $question->section->chapter->part->certification;
        Enrollment::factory()->for($student)->for($cert)->learning()->create();
        $policy = new SectionQuestionPolicy;

        $this->assertTrue($policy->view($student, $question));
    }

    public function test_student_without_enrollment_cannot_view(): void
    {
        $student = User::factory()->student()->create();
        $question = SectionQuestion::factory()->published()->create();
        $policy = new SectionQuestionPolicy;

        $this->assertFalse($policy->view($student, $question), '未受講の student は view 不可');
    }

    public function test_student_cannot_view_draft_question(): void
    {
        $student = User::factory()->student()->create();
        $question = SectionQuestion::factory()->draft()->create();
        $cert = $question->section->chapter->part->certification;
        Enrollment::factory()->for($student)->for($cert)->learning()->create();
        $policy = new SectionQuestionPolicy;

        $this->assertFalse($policy->view($student, $question), 'draft の question は受講中でも閲覧不可');
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
        $assignedSection = Section::factory()->state(fn () => [
            'chapter_id' => Chapter::factory()
                ->for(Part::factory()->for($assignedCert))
                ->create()->id,
        ])->create();
        $assignedQuestion = SectionQuestion::factory()->draft()->create([
            'section_id' => $assignedSection->id,
        ]);

        $otherSection = Section::factory()->state(fn () => [
            'chapter_id' => Chapter::factory()
                ->for(Part::factory()->for($otherCert))
                ->create()->id,
        ])->create();
        $otherQuestion = SectionQuestion::factory()->published()->create([
            'section_id' => $otherSection->id,
        ]);

        $policy = new SectionQuestionPolicy;

        // 担当資格に対する全 ability の許可検証（draft の question でも閲覧可能）
        $this->assertTrue($policy->viewAny($coach, $assignedSection));
        $this->assertTrue($policy->view($coach, $assignedQuestion), 'coach は担当資格の draft question も閲覧できるはず');
        $this->assertTrue($policy->create($coach, $assignedSection));
        $this->assertTrue($policy->update($coach, $assignedQuestion));
        $this->assertTrue($policy->delete($coach, $assignedQuestion));
        $this->assertTrue($policy->publish($coach, $assignedQuestion));
        $this->assertTrue($policy->unpublish($coach, $assignedQuestion));

        // 非担当資格に対する全 ability の拒否検証
        $this->assertFalse($policy->viewAny($coach, $otherSection));
        $this->assertFalse($policy->view($coach, $otherQuestion));
        $this->assertFalse($policy->create($coach, $otherSection));
        $this->assertFalse($policy->update($coach, $otherQuestion));
        $this->assertFalse($policy->delete($coach, $otherQuestion));
        $this->assertFalse($policy->publish($coach, $otherQuestion));
        $this->assertFalse($policy->unpublish($coach, $otherQuestion));
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
        $section = Section::factory()->state(fn () => [
            'chapter_id' => Chapter::factory()
                ->for(Part::factory()->for($cert))
                ->create()->id,
        ])->create();
        $question = SectionQuestion::factory()->published()->create([
            'section_id' => $section->id,
        ]);
        $policy = new SectionQuestionPolicy;

        $this->assertFalse($policy->viewAny($coach, $section));
        $this->assertFalse($policy->view($coach, $question));
        $this->assertFalse($policy->create($coach, $section));
        $this->assertFalse($policy->update($coach, $question));
        $this->assertFalse($policy->delete($coach, $question));
        $this->assertFalse($policy->publish($coach, $question));
        $this->assertFalse($policy->unpublish($coach, $question));
    }
}
