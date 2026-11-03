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

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_assigned_coach_can_view_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert);
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->actingAs($coach)
            ->get(route('admin.sections.show', $section))
            ->assertOk()
            ->assertViewIs('section.management.show')
            ->assertViewHas('section');
    }

    public function test_assigned_coach_can_create_update_and_destroy_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert);
        [$part, $chapter] = $this->makePartChain($cert, 'draft');

        // 作成
        $this->actingAs($coach)
            ->post(route('admin.chapters.sections.store', $chapter), [
                'title' => 'コーチ作成セクション',
                'body' => '本文内容',
            ])
            ->assertRedirect();
        $section = Section::where('title', 'コーチ作成セクション')->firstOrFail();

        // 更新
        $this->actingAs($coach)
            ->patch(route('admin.sections.update', $section), [
                'title' => '更新後セクション',
                'body' => '更新後本文',
            ])
            ->assertRedirect(route('admin.sections.show', $section));
        $this->assertSame('更新後セクション', $section->fresh()->title);

        // 削除
        $this->actingAs($coach)
            ->delete(route('admin.sections.destroy', $section))
            ->assertRedirect(route('admin.chapters.show', $chapter));
        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
    }

    public function test_assigned_coach_can_publish_and_unpublish_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert);
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        // 公開
        $this->actingAs($coach)
            ->post(route('admin.sections.publish', $section))
            ->assertRedirect(route('admin.sections.show', $section));
        $this->assertSame('published', $section->fresh()->status->value);

        // 下書きに戻す
        $this->actingAs($coach)
            ->post(route('admin.sections.unpublish', $section))
            ->assertRedirect(route('admin.sections.show', $section));
        $this->assertSame('draft', $section->fresh()->status->value);
    }

    public function test_assigned_coach_can_preview_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert);
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->actingAs($coach)
            ->postJson(route('admin.sections.preview', $section), [
                'body' => "# 見出し\n\nコーチによるプレビュー",
            ])
            ->assertOk()
            ->assertJsonStructure(['html']);
    }

    public function test_non_assigned_coach_cannot_manage_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        // 詳細閲覧拒否
        $this->actingAs($coach)
            ->get(route('admin.sections.show', $section))
            ->assertForbidden();

        // 作成拒否
        $this->actingAs($coach)
            ->post(route('admin.chapters.sections.store', $chapter), [
                'title' => '拒否',
                'body' => '拒否本文',
            ])
            ->assertForbidden();

        // 更新拒否
        $this->actingAs($coach)
            ->patch(route('admin.sections.update', $section), [
                'title' => '拒否',
                'body' => '拒否本文',
            ])
            ->assertForbidden();

        // プレビュー拒否
        $this->actingAs($coach)
            ->postJson(route('admin.sections.preview', $section), [
                'body' => '拒否',
            ])
            ->assertForbidden();

        // 公開拒否
        $this->actingAs($coach)
            ->post(route('admin.sections.publish', $section))
            ->assertForbidden();

        // 下書き化拒否
        $publishedSection = Section::factory()->forChapter($chapter)->published()->create();
        $this->actingAs($coach)
            ->post(route('admin.sections.unpublish', $publishedSection))
            ->assertForbidden();

        // 削除拒否
        $this->actingAs($coach)
            ->delete(route('admin.sections.destroy', $section))
            ->assertForbidden();
    }

    public function test_store_section_includes_body(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.chapters.sections.store', $chapter), [
                'title' => 'はじめに',
                'body' => '## 概要

本セクションでは...',
            ])
            ->assertRedirect();

        $section = Section::where('title', 'はじめに')->firstOrFail();
        $this->assertStringContainsString('概要', $section->body);
        $this->assertSame('draft', $section->status->value);
    }

    public function test_update_updates_body(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $this->actingAs($admin)
            ->patch(route('admin.sections.update', $section), [
                'title' => '新タイトル',
                'body' => 'updated body',
            ])
            ->assertRedirect();

        $section->refresh();
        $this->assertSame('新タイトル', $section->title);
        $this->assertSame('updated body', $section->body);
    }

    public function test_preview_returns_html(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $this->actingAs($admin)
            ->postJson(route('admin.sections.preview', $section), [
                'body' => "# タイトル\n\n本文",
            ])
            ->assertOk()
            ->assertJsonStructure(['html']);
    }
}
