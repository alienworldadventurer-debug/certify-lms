<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_perform_each_allowed_transition_and_updates_actor(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create([
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);
        $createdAt = $plan->created_at;
        $transitions = [
            ['publish', PlanStatus::Published, 'プランを公開しました。'],
            ['archive', PlanStatus::Archived, 'プランをアーカイブしました。'],
            ['unarchive', PlanStatus::Draft, 'プランを下書きに戻しました。'],
        ];

        foreach ($transitions as [$operation, $expectedStatus, $message]) {
            $previousUpdatedAt = $plan->fresh()->updated_at;
            $this->travel(1)->seconds();

            $this->actingAs($admin)
                ->post(route('admin.plans.'.$operation, $plan))
                ->assertRedirect(route('admin.plans.show', $plan))
                ->assertSessionHas('success', $message);

            $plan->refresh();
            $this->assertSame($expectedStatus, $plan->status);
            $this->assertSame($creator->id, $plan->created_by_user_id);
            $this->assertSame($admin->id, $plan->updated_by_user_id);
            $this->assertSame($createdAt->toDateTimeString(), $plan->created_at->toDateTimeString());
            $this->assertTrue($plan->updated_at->greaterThan($previousUpdatedAt));
        }
    }

    public function test_disallowed_transitions_keep_status_and_metadata_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $cases = [
            [PlanStatus::Draft, 'unarchive', '下書きのプランは公開のみ可能です。'],
            [PlanStatus::Draft, 'archive', '下書きのプランは公開のみ可能です。'],
            [PlanStatus::Published, 'publish', '公開中のプランはアーカイブのみ可能です。'],
            [PlanStatus::Published, 'unarchive', '公開中のプランはアーカイブのみ可能です。'],
            [PlanStatus::Archived, 'archive', 'アーカイブ済みのプランは下書きに戻すことのみ可能です。'],
            [PlanStatus::Archived, 'publish', 'アーカイブ済みのプランは下書きに戻すことのみ可能です。'],
        ];

        foreach ($cases as [$status, $operation, $message]) {
            $plan = Plan::factory()->create(['status' => $status]);
            $originalUpdatedAt = $plan->updated_at->toDateTimeString();
            $originalUpdatedBy = $plan->updated_by_user_id;
            $this->travel(1)->seconds();

            $this->actingAs($admin)
                ->post(route('admin.plans.'.$operation, $plan))
                ->assertRedirect(route('admin.plans.show', $plan))
                ->assertSessionHas('error', $message);

            $this->assertSame($status, $plan->fresh()->status);
            $this->assertSame($originalUpdatedBy, $plan->fresh()->updated_by_user_id);
            $this->assertSame($originalUpdatedAt, $plan->fresh()->updated_at->toDateTimeString());
        }
    }
}
