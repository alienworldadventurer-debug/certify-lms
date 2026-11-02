<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\Services\LearningProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarize_returns_zeros_when_no_content_exists(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->for(Certification::factory()->published())->learning()->create();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(0, $summary->sectionsTotal);
        $this->assertSame(0, $summary->sectionsCompleted);
        $this->assertSame(0.0, $summary->sectionCompletionRatio);
        $this->assertSame(0, $summary->chaptersTotal);
        $this->assertSame(0, $summary->chaptersCompleted);
        $this->assertSame(0.0, $summary->chapterCompletionRatio);
        $this->assertSame(0, $summary->partsTotal);
        $this->assertSame(0, $summary->partsCompleted);
        $this->assertSame(0.0, $summary->partCompletionRatio);
        $this->assertSame(0.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_counts_only_published_content_at_each_tier(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        $part1 = Part::factory()->published()->forCertification($certification)->create();
        $part2 = Part::factory()->published()->forCertification($certification)->create();

        $chapterA = Chapter::factory()->published()->forPart($part1)->create();
        $chapterB = Chapter::factory()->published()->forPart($part1)->create();
        $chapterC = Chapter::factory()->published()->forPart($part2)->create();

        $s1 = Section::factory()->published()->forChapter($chapterA)->create();
        $s2 = Section::factory()->published()->forChapter($chapterA)->create();
        Section::factory()->published()->forChapter($chapterB)->create();
        $s4 = Section::factory()->published()->forChapter($chapterC)->create();
        Section::factory()->draft()->forChapter($chapterC)->create();

        SectionProgress::factory()->forEnrollment($enrollment)->forSection($s1)->completedNow()->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($s2)->completedNow()->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($s4)->completedNow()->create();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(4, $summary->sectionsTotal);
        $this->assertSame(3, $summary->sectionsCompleted);
        $this->assertEqualsWithDelta(0.75, $summary->sectionCompletionRatio, 0.0001);
        $this->assertSame(3, $summary->chaptersTotal);
        $this->assertSame(2, $summary->chaptersCompleted);
        $this->assertEqualsWithDelta(0.6667, $summary->chapterCompletionRatio, 0.0001);
        $this->assertSame(2, $summary->partsTotal);
        $this->assertSame(1, $summary->partsCompleted);
        $this->assertEqualsWithDelta(0.5, $summary->partCompletionRatio, 0.0001);
        $this->assertEqualsWithDelta(0.75, $summary->overallCompletionRatio, 0.0001);
    }

    public function test_summarize_ignores_progress_of_other_enrollments(): void
    {
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->published()->forCertification($certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        $section = Section::factory()->published()->forChapter($chapter)->create();

        $target = Enrollment::factory()->for(User::factory()->student()->inProgress()->create())->for($certification)->learning()->create();
        $other = Enrollment::factory()->for(User::factory()->student()->inProgress()->create())->for($certification)->learning()->create();
        SectionProgress::factory()->forEnrollment($other)->forSection($section)->completedNow()->create();

        $summary = app(LearningProgressService::class)->summarize($target);

        $this->assertSame(0, $summary->sectionsCompleted);
        $this->assertSame(0.0, $summary->overallCompletionRatio);
    }

    public function test_batch_section_ratios_returns_empty_array_for_no_enrollments(): void
    {
        $result = app(LearningProgressService::class)->batchSectionRatios(collect());

        $this->assertSame([], $result);
    }

    public function test_batch_section_ratios_returns_ratio_per_enrollment_keyed_by_id(): void
    {
        $certification = Certification::factory()->published()->create();
        $emptyCertification = Certification::factory()->published()->create();
        $part = Part::factory()->published()->forCertification($certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        $s1 = Section::factory()->published()->forChapter($chapter)->create();
        Section::factory()->published()->forChapter($chapter)->create();

        $withProgress = Enrollment::factory()->for(User::factory()->student()->inProgress()->create())->for($certification)->learning()->create();
        $withoutSections = Enrollment::factory()->for(User::factory()->student()->inProgress()->create())->for($emptyCertification)->learning()->create();
        SectionProgress::factory()->forEnrollment($withProgress)->forSection($s1)->completedNow()->create();

        $result = app(LearningProgressService::class)->batchSectionRatios(collect([$withProgress, $withoutSections]));

        $this->assertEqualsWithDelta(0.5, $result[$withProgress->id], 0.0001);
        $this->assertSame(0.0, $result[$withoutSections->id]);
    }

    public function test_batch_section_ratios_calculates_independently_for_multiple_enrollments_in_same_certification(): void
    {
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->published()->forCertification($certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        $s1 = Section::factory()->published()->forChapter($chapter)->create();
        $s2 = Section::factory()->published()->forChapter($chapter)->create();

        $enrollmentA = Enrollment::factory()->for(User::factory()->student()->inProgress()->create())->for($certification)->learning()->create();
        $enrollmentB = Enrollment::factory()->for(User::factory()->student()->inProgress()->create())->for($certification)->learning()->create();
        $enrollmentC = Enrollment::factory()->for(User::factory()->student()->inProgress()->create())->for($certification)->learning()->create();

        // enrollmentA: 1/2 (0.5), enrollmentB: 2/2 (1.0), enrollmentC: 0/2 (0.0)
        SectionProgress::factory()->forEnrollment($enrollmentA)->forSection($s1)->completedNow()->create();

        SectionProgress::factory()->forEnrollment($enrollmentB)->forSection($s1)->completedNow()->create();
        SectionProgress::factory()->forEnrollment($enrollmentB)->forSection($s2)->completedNow()->create();

        $result = app(LearningProgressService::class)->batchSectionRatios(collect([$enrollmentA, $enrollmentB, $enrollmentC]));

        $this->assertEqualsWithDelta(0.5, $result[$enrollmentA->id], 0.0001);
        $this->assertEqualsWithDelta(1.0, $result[$enrollmentB->id], 0.0001);
        $this->assertSame(0.0, $result[$enrollmentC->id]);
    }
}
