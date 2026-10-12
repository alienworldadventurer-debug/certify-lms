<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_draft_with_trimmed_values_and_default_order(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.plans.store'), [
            'name' => '  3ヶ月プラン  ',
            'description' => '  プラン説明  ',
            'duration_days' => 90,
            'default_meeting_quota' => 12,
            'status' => PlanStatus::Published->value,
        ]);

        $plan = Plan::query()->firstOrFail();

        $response->assertRedirect(route('admin.plans.show', $plan));
        $response->assertSessionHas('success', 'プランを作成しました。');
        $this->assertSame('3ヶ月プラン', $plan->name);
        $this->assertSame('プラン説明', $plan->description);
        $this->assertSame(0, $plan->sort_order);
        $this->assertSame(PlanStatus::Draft, $plan->status);
        $this->assertSame($admin->id, $plan->created_by_user_id);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_admin_can_create_at_inclusive_field_boundaries(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = [
            'name' => str_repeat('a', 100),
            'description' => str_repeat('b', 2000),
            'duration_days' => 3650,
            'default_meeting_quota' => 1000,
            'sort_order' => 4294967295,
        ];

        $this->actingAs($admin)
            ->post(route('admin.plans.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('plans', [
            'name' => $payload['name'],
            'duration_days' => 3650,
            'default_meeting_quota' => 1000,
            'sort_order' => 4294967295,
        ]);
    }

    public function test_required_fields_and_invalid_boundaries_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $valid = [
            'name' => 'Valid Plan',
            'description' => null,
            'duration_days' => 1,
            'default_meeting_quota' => 0,
            'sort_order' => 0,
        ];
        $invalidCases = [
            ['name', '   ', 'プラン名は必須です。'],
            ['name', str_repeat('a', 101), 'プラン名は100文字以内で入力してください。'],
            ['description', str_repeat('a', 2001), '説明は2,000文字以内で入力してください。'],
            ['duration_days', '', '受講期間は必須です。'],
            ['duration_days', 0, '受講期間は1〜3,650日の整数で入力してください。'],
            ['duration_days', 3651, '受講期間は1〜3,650日の整数で入力してください。'],
            ['duration_days', '1.5', '受講期間は1〜3,650日の整数で入力してください。'],
            ['default_meeting_quota', '', '初期付与面談回数は必須です。'],
            ['default_meeting_quota', -1, '初期付与面談回数は0〜1,000の整数で入力してください。'],
            ['default_meeting_quota', 1001, '初期付与面談回数は0〜1,000の整数で入力してください。'],
            ['default_meeting_quota', '1.5', '初期付与面談回数は0〜1,000の整数で入力してください。'],
            ['sort_order', -1, '並び順は0〜4,294,967,295の整数で入力してください。'],
            ['sort_order', 4294967296, '並び順は0〜4,294,967,295の整数で入力してください。'],
            ['sort_order', '1.5', '並び順は0〜4,294,967,295の整数で入力してください。'],
        ];

        foreach ($invalidCases as [$field, $value, $message]) {
            $this->actingAs($admin)
                ->from(route('admin.plans.create'))
                ->post(route('admin.plans.store'), [...$valid, $field => $value])
                ->assertSessionHasErrors([$field => $message]);
        }

        $this->assertDatabaseCount('plans', 0);
    }

    public function test_admin_can_update_any_status_without_changing_creator_or_status(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->admin()->create();
        $payload = [
            'name' => '  更新済みプラン  ',
            'description' => '  説明  ',
            'duration_days' => 180,
            'default_meeting_quota' => 24,
            'sort_order' => 50,
            'status' => PlanStatus::Archived->value,
        ];

        foreach (PlanStatus::cases() as $status) {
            $plan = Plan::factory()->create([
                'status' => $status,
                'created_by_user_id' => $creator->id,
                'updated_by_user_id' => $creator->id,
            ]);
            $createdAt = $plan->created_at;

            $this->actingAs($admin)
                ->put(route('admin.plans.update', $plan), $payload)
                ->assertRedirect(route('admin.plans.show', $plan))
                ->assertSessionHas('success', 'プランを更新しました。');

            $plan->refresh();
            $this->assertSame('更新済みプラン', $plan->name);
            $this->assertSame('説明', $plan->description);
            $this->assertSame(180, $plan->duration_days);
            $this->assertSame(24, $plan->default_meeting_quota);
            $this->assertSame(50, $plan->sort_order);
            $this->assertSame($status, $plan->status);
            $this->assertSame($creator->id, $plan->created_by_user_id);
            $this->assertSame($admin->id, $plan->updated_by_user_id);
            $this->assertSame($createdAt->toDateTimeString(), $plan->created_at->toDateTimeString());
        }
    }

    public function test_update_rejects_invalid_values_and_preserves_existing_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create(['name' => 'Unchanged']);
        $valid = [
            'name' => 'Updated',
            'description' => null,
            'duration_days' => 30,
            'default_meeting_quota' => 5,
            'sort_order' => 0,
        ];

        $this->actingAs($admin)
            ->from(route('admin.plans.edit', $plan))
            ->put(route('admin.plans.update', $plan), [...$valid, 'duration_days' => '1.5'])
            ->assertSessionHasErrors([
                'duration_days' => '受講期間は1〜3,650日の整数で入力してください。',
            ])
            ->assertSessionHas('_old_input.name', 'Updated');

        $this->assertSame('Unchanged', $plan->fresh()->name);
    }

    public function test_detail_shows_metadata_and_only_active_student_users_for_this_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();
        $otherPlan = Plan::factory()->create();
        $activeStudent = User::factory()->student()->inProgress()->withPlan($plan)->create();
        User::factory()->student()->invited()->withPlan($plan)->create();
        User::factory()->student()->graduated()->withPlan($plan)->create();
        User::factory()->student()->withdrawn()->withPlan($plan)->create();
        User::factory()->coach()->inProgress()->withPlan($plan)->create();
        User::factory()->student()->inProgress()->withPlan($otherPlan)->create();

        $response = $this->actingAs($admin)->get(route('admin.plans.show', $plan));

        $response->assertOk()
            ->assertSee($activeStudent->name)
            ->assertSee($activeStudent->email)
            ->assertSee('受講者数')
            ->assertViewHas('plan', fn (Plan $loadedPlan): bool => $loadedPlan->users->count() === 1
                && $loadedPlan->users->first()->is($activeStudent));
    }

    public function test_admin_can_delete_only_unreferenced_draft_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $this->actingAs($admin)
            ->delete(route('admin.plans.destroy', $plan))
            ->assertRedirect(route('admin.plans.index'))
            ->assertSessionHas('success', 'プランを削除しました。');

        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    public function test_plan_cannot_be_deleted_when_user_or_history_references_it(): void
    {
        $admin = User::factory()->admin()->create();
        $userReferencedPlan = Plan::factory()->draft()->create();
        $user = User::factory()->student()->invited()->withPlan($userReferencedPlan)->create();
        $historyReferencedPlan = Plan::factory()->draft()->create();
        UserPlanLog::factory()->create([
            'plan_id' => $historyReferencedPlan->id,
        ]);
        $softDeletedUserPlan = Plan::factory()->draft()->create();
        $softDeletedUser = User::factory()->student()->withPlan($softDeletedUserPlan)->create();
        $softDeletedUser->delete();

        foreach ([$userReferencedPlan, $historyReferencedPlan, $softDeletedUserPlan] as $plan) {
            $this->actingAs($admin)
                ->delete(route('admin.plans.destroy', $plan))
                ->assertRedirect(route('admin.plans.show', $plan))
                ->assertSessionHas('error', 'このプランは削除できません。下書きで、受講者およびプラン履歴から参照されていないことを確認してください。');

            $this->assertDatabaseHas('plans', ['id' => $plan->id]);
        }

        $this->assertDatabaseHas('users', ['id' => $user->id, 'plan_id' => $userReferencedPlan->id]);
        $this->assertDatabaseHas('user_plan_logs', ['plan_id' => $historyReferencedPlan->id]);
    }

    public function test_published_and_archived_plans_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([PlanStatus::Published, PlanStatus::Archived] as $status) {
            $plan = Plan::factory()->create(['status' => $status]);

            $this->actingAs($admin)
                ->delete(route('admin.plans.destroy', $plan))
                ->assertRedirect(route('admin.plans.show', $plan))
                ->assertSessionHas('error');

            $this->assertDatabaseHas('plans', ['id' => $plan->id]);
        }
    }
}
