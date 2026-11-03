<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chapter;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_assigned_coach_can_create_update_and_destroy_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert);
        $part = Part::factory()->forCertification($cert)->draft()->create();

        // 作成
        $this->actingAs($coach)
            ->post(route('admin.parts.chapters.store', $part), ['title' => '第1章 コーチ作成'])
            ->assertRedirect();
        $chapter = Chapter::where('title', '第1章 コーチ作成')->firstOrFail();

        // 更新
        $this->actingAs($coach)
            ->patch(route('admin.chapters.update', $chapter), ['title' => '第1章 更新後'])
            ->assertRedirect(route('admin.chapters.show', $chapter));
        $this->assertSame('第1章 更新後', $chapter->fresh()->title);

        // 削除
        $this->actingAs($coach)
            ->delete(route('admin.chapters.destroy', $chapter))
            ->assertRedirect(route('admin.parts.show', $part));
        $this->assertDatabaseMissing('chapters', ['id' => $chapter->id]);
    }

    public function test_assigned_coach_can_publish_and_unpublish_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert);
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($coach)
            ->post(route('admin.chapters.publish', $chapter))
            ->assertRedirect(route('admin.chapters.show', $chapter));
        $this->assertSame('published', $chapter->fresh()->status->value);

        $this->actingAs($coach)
            ->post(route('admin.chapters.unpublish', $chapter))
            ->assertRedirect(route('admin.chapters.show', $chapter));
        $this->assertSame('draft', $chapter->fresh()->status->value);
    }

    public function test_non_assigned_coach_cannot_manage_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        // 作成拒否
        $this->actingAs($coach)
            ->post(route('admin.parts.chapters.store', $part), ['title' => '拒否'])
            ->assertForbidden();

        // 更新拒否
        $this->actingAs($coach)
            ->patch(route('admin.chapters.update', $chapter), ['title' => '拒否'])
            ->assertForbidden();

        // 公開拒否
        $this->actingAs($coach)
            ->post(route('admin.chapters.publish', $chapter))
            ->assertForbidden();

        // 下書き化拒否
        $publishedChapter = Chapter::factory()->forPart($part)->published()->create();
        $this->actingAs($coach)
            ->post(route('admin.chapters.unpublish', $publishedChapter))
            ->assertForbidden();

        // 削除拒否
        $this->actingAs($coach)
            ->delete(route('admin.chapters.destroy', $chapter))
            ->assertForbidden();
    }

    public function test_store_creates_draft_chapter(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.parts.chapters.store', $part), ['title' => '第1章'])
            ->assertRedirect();

        $chapter = Chapter::where('title', '第1章')->firstOrFail();
        $this->assertSame('draft', $chapter->status->value);
        $this->assertSame(1, $chapter->order);
    }

    public function test_publish_then_unpublish(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.chapters.publish', $chapter))
            ->assertRedirect();
        $this->assertSame('published', $chapter->fresh()->status->value);

        $this->actingAs($admin)
            ->post(route('admin.chapters.unpublish', $chapter))
            ->assertRedirect();
        $this->assertSame('draft', $chapter->fresh()->status->value);
    }

    public function test_destroy_draft_only(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.chapters.destroy', $chapter))
            ->assertStatus(409);
    }
}
