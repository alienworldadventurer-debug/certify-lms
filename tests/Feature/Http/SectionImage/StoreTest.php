<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SectionImage;

use App\Models\Certification;
use App\Models\SectionImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_assigned_coach_can_upload_and_delete_image(): void
    {
        Storage::fake('public');
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert);
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $file = UploadedFile::fake()->image('diagram.png', 400, 300);

        // アップロード
        $response = $this->actingAs($coach)
            ->postJson(route('admin.sections.images.store', $section), [
                'file' => $file,
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'url', 'alt_placeholder']);

        $image = SectionImage::where('section_id', $section->id)->firstOrFail();

        // 削除
        $this->actingAs($coach)
            ->deleteJson(route('admin.section-images.destroy', $image))
            ->assertNoContent();

        $this->assertDatabaseMissing('section_images', ['id' => $image->id]);
    }

    public function test_non_assigned_coach_cannot_manage_images(): void
    {
        Storage::fake('public');
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $file = UploadedFile::fake()->image('diagram.png', 400, 300);

        // アップロード拒否
        $this->actingAs($coach)
            ->postJson(route('admin.sections.images.store', $section), [
                'file' => $file,
            ])
            ->assertForbidden();

        $image = SectionImage::factory()->for($section)->create();

        // 削除拒否
        $this->actingAs($coach)
            ->deleteJson(route('admin.section-images.destroy', $image))
            ->assertForbidden();
    }

    public function test_admin_can_upload_image(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $file = UploadedFile::fake()->image('cover.png', 800, 600);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.sections.images.store', $section), [
                'file' => $file,
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'url', 'alt_placeholder']);

        $this->assertDatabaseHas('section_images', [
            'section_id' => $section->id,
            'mime_type' => 'image/png',
        ]);

        $payload = $response->json();
        $path = ltrim(str_replace('/storage/', '', $payload['url']), '/');
        Storage::disk('public')->assertExists($path);
    }

    public function test_rejects_oversized_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $file = UploadedFile::fake()->create('big.png', 3000, 'image/png');

        $this->actingAs($admin)
            ->postJson(route('admin.sections.images.store', $section), ['file' => $file])
            ->assertStatus(422);
    }

    public function test_rejects_invalid_mime(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $file = UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml');

        $this->actingAs($admin)
            ->postJson(route('admin.sections.images.store', $section), ['file' => $file])
            ->assertStatus(422);
    }
}
