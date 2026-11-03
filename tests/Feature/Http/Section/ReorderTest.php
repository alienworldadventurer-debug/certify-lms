<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Section;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class ReorderTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_assigned_coach_can_reorder_sections(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert);
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        $a = Section::factory()->forChapter($chapter)->state(['order' => 1])->create();
        $b = Section::factory()->forChapter($chapter)->state(['order' => 2])->create();

        $this->actingAs($coach)
            ->patch(route('admin.chapters.sections.reorder', $chapter), [
                'ids' => [$b->id, $a->id],
            ])
            ->assertRedirect(route('admin.chapters.show', $chapter));

        $this->assertSame(1, $b->fresh()->order);
        $this->assertSame(2, $a->fresh()->order);
    }

    public function test_non_assigned_coach_cannot_reorder_sections(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        $a = Section::factory()->forChapter($chapter)->state(['order' => 1])->create();
        $b = Section::factory()->forChapter($chapter)->state(['order' => 2])->create();

        $this->actingAs($coach)
            ->patch(route('admin.chapters.sections.reorder', $chapter), [
                'ids' => [$b->id, $a->id],
            ])
            ->assertForbidden();
    }

    public function test_reorder_updates_order_columns(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        $a = Section::factory()->forChapter($chapter)->state(['order' => 1])->create();
        $b = Section::factory()->forChapter($chapter)->state(['order' => 2])->create();
        $c = Section::factory()->forChapter($chapter)->state(['order' => 3])->create();

        $this->actingAs($admin)
            ->patch(route('admin.chapters.sections.reorder', $chapter), [
                'ids' => [$c->id, $a->id, $b->id],
            ])
            ->assertRedirect(route('admin.chapters.show', $chapter));

        $this->assertSame(1, $c->fresh()->order);
        $this->assertSame(2, $a->fresh()->order);
        $this->assertSame(3, $b->fresh()->order);
    }

    public function test_reorder_rejects_cross_chapter_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        $otherChapter = Chapter::factory()->forPart($part)->published()->create();
        $a = Section::factory()->forChapter($chapter)->state(['order' => 1])->create();
        $foreign = Section::factory()->forChapter($otherChapter)->state(['order' => 1])->create();

        $this->actingAs($admin)
            ->patchJson(route('admin.chapters.sections.reorder', $chapter), [
                'ids' => [$a->id, $foreign->id],
            ])
            ->assertStatus(422);
    }
}
