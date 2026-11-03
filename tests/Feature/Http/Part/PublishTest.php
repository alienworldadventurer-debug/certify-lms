<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class PublishTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_assigned_coach_can_publish_and_unpublish_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert);
        $part = Part::factory()->forCertification($cert)->draft()->create();

        // 公開
        $this->actingAs($coach)
            ->post(route('admin.parts.publish', $part))
            ->assertRedirect(route('admin.parts.show', $part));
        $this->assertSame('published', $part->fresh()->status->value);

        // 下書きに戻す
        $this->actingAs($coach)
            ->post(route('admin.parts.unpublish', $part))
            ->assertRedirect(route('admin.parts.show', $part));
        $this->assertSame('draft', $part->fresh()->status->value);
    }

    public function test_non_assigned_coach_cannot_publish_or_unpublish_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($coach)
            ->post(route('admin.parts.publish', $part))
            ->assertForbidden();

        $publishedPart = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($coach)
            ->post(route('admin.parts.unpublish', $publishedPart))
            ->assertForbidden();
    }

    public function test_admin_can_publish_draft_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.parts.publish', $part))
            ->assertRedirect(route('admin.parts.show', $part));

        $part->refresh();
        $this->assertSame('published', $part->status->value);
        $this->assertNotNull($part->published_at);
    }

    public function test_cannot_publish_already_published(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($admin)
            ->postJson(route('admin.parts.publish', $part))
            ->assertStatus(409);
    }

    public function test_admin_can_unpublish_published_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($admin)
            ->post(route('admin.parts.unpublish', $part))
            ->assertRedirect(route('admin.parts.show', $part));

        $this->assertSame('draft', $part->fresh()->status->value);
    }
}
