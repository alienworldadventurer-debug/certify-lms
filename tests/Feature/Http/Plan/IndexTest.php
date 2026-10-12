<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_by_trimmed_name_keyword_and_status(): void
    {
        $admin = User::factory()->admin()->create();
        $match = Plan::factory()->published()->create(['name' => 'Standard Plan']);
        Plan::factory()->draft()->create(['name' => 'Standard Draft']);
        Plan::factory()->published()->create([
            'name' => 'Unrelated',
            'description' => 'Standard in description only',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.plans.index', ['keyword' => '  tandard  ', 'status' => 'published']));

        $response->assertOk();
        $response->assertViewHas('plans', fn ($plans): bool => $plans->total() === 1
            && $plans->first()->is($match));
        $response->assertViewHas('keyword', 'tandard');
        $response->assertViewHas('status', 'published');
    }

    public function test_index_orders_by_sort_order_then_newest_creation_time(): void
    {
        $admin = User::factory()->admin()->create();
        $older = Plan::factory()->create([
            'sort_order' => 5,
            'created_at' => now()->subMinute(),
        ]);
        $newer = Plan::factory()->create([
            'sort_order' => 5,
            'created_at' => now(),
        ]);
        $first = Plan::factory()->create(['sort_order' => 0]);
        $second = Plan::factory()->create(['sort_order' => 1]);

        $this->actingAs($admin)
            ->get(route('admin.plans.index'))
            ->assertViewHas('plans', fn ($plans): bool => $plans->getCollection()->modelKeys() === [
                $first->id,
                $second->id,
                $newer->id,
                $older->id,
            ]);
    }

    public function test_index_has_twenty_items_per_page_and_preserves_filters(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->count(21)->published()->create(['name' => 'Keep Match']);

        $response = $this->actingAs($admin)->get(route('admin.plans.index', [
            'keyword' => 'Keep',
            'status' => PlanStatus::Published->value,
        ]));

        $nextPageUrl = null;
        $response->assertViewHas('plans', function ($plans) use (&$nextPageUrl): bool {
            $nextPageUrl = $plans->nextPageUrl();

            return $plans->perPage() === 20
                && $plans->count() === 20
                && str_contains($nextPageUrl, 'keyword=Keep')
                && str_contains($nextPageUrl, 'status=published');
        });

        $this->get($nextPageUrl)
            ->assertOk()
            ->assertViewHas('plans', fn ($plans): bool => $plans->count() === 1
                && $plans->currentPage() === 2);
    }

    public function test_invalid_status_is_removed_and_keyword_is_preserved(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.plans.index', ['keyword' => '  Keep  ', 'status' => 'invalid']))
            ->assertRedirect(route('admin.plans.index', ['keyword' => 'Keep']))
            ->assertSessionHas('warning', '指定されたステータスは無効です。ステータス条件を適用せずに表示しています。');
    }

    public function test_status_array_is_rejected_as_an_invalid_filter(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.plans.index', ['keyword' => 'Keep', 'status' => ['draft']]))
            ->assertRedirect(route('admin.plans.index', ['keyword' => 'Keep']))
            ->assertSessionHas('warning', '指定されたステータスは無効です。ステータス条件を適用せずに表示しています。');
    }

    public function test_keyword_is_limited_to_one_hundred_characters(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.plans.index', ['keyword' => str_repeat('x', 101)]))
            ->assertSessionHasErrors(['keyword' => '検索キーワードは100文字以内で入力してください。']);
    }

    public function test_whitespace_only_keyword_is_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.plans.index', ['keyword' => '   ']))
            ->assertOk()
            ->assertViewHas('keyword', '')
            ->assertViewHas('plans', fn ($plans): bool => $plans->total() === 1);
    }

    public function test_index_counts_only_in_progress_students_on_the_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();
        User::factory()->student()->inProgress()->withPlan($plan)->create();
        User::factory()->student()->invited()->withPlan($plan)->create();
        User::factory()->student()->graduated()->withPlan($plan)->create();
        User::factory()->student()->withdrawn()->withPlan($plan)->create();
        User::factory()->coach()->inProgress()->withPlan($plan)->create();

        $this->actingAs($admin)
            ->get(route('admin.plans.index'))
            ->assertViewHas('plans', fn ($plans): bool => $plans->firstWhere('id', $plan->id)->users_count === 1);
    }
}
