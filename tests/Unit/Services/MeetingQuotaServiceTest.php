<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MeetingQuotaTransactionType;
use App\Models\MeetingQuotaTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MeetingQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_returns_max_meetings_when_no_transactions(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 4]);

        $this->assertSame(4, app(MeetingQuotaService::class)->remaining($user));
    }

    public function test_remaining_excludes_granted_initial_to_avoid_double_count(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 4]);
        MeetingQuotaTransaction::factory()->grantedInitial(4)->state(['user_id' => $user->id])->create();

        // granted_initial は max_meetings と二重カウントしないため、残数は max_meetings(4)のまま。
        $this->assertSame(4, app(MeetingQuotaService::class)->remaining($user));
    }

    public function test_remaining_with_mixed_transactions(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 4]);
        $admin = User::factory()->admin()->create();

        MeetingQuotaTransaction::factory()->grantedInitial(4)->state(['user_id' => $user->id])->create();
        MeetingQuotaTransaction::factory()->consumed()->state(['user_id' => $user->id])->create();
        MeetingQuotaTransaction::factory()->consumed()->state(['user_id' => $user->id])->create();
        MeetingQuotaTransaction::factory()->refunded()->state(['user_id' => $user->id])->create();
        MeetingQuotaTransaction::factory()->purchased(5)->state(['user_id' => $user->id])->create();
        MeetingQuotaTransaction::factory()->adminGrant($admin, 2)->state(['user_id' => $user->id])->create();

        // 4 (max) + (-1) + (-1) + 1 + 5 + 2 = 10
        $this->assertSame(10, app(MeetingQuotaService::class)->remaining($user));
    }

    public function test_remaining_isolates_users(): void
    {
        $user1 = User::factory()->student()->create(['max_meetings' => 3]);
        $user2 = User::factory()->student()->create(['max_meetings' => 5]);
        MeetingQuotaTransaction::factory()->consumed()->state(['user_id' => $user1->id])->create();

        $service = app(MeetingQuotaService::class);

        $this->assertSame(2, $service->remaining($user1));
        $this->assertSame(5, $service->remaining($user2));
    }

    public function test_remaining_for_users_batches_transactions_and_keeps_user_totals_separate(): void
    {
        $plan = Plan::factory()->create();
        $user1 = User::factory()->student()->withPlan($plan)->create(['max_meetings' => 12]);
        $user2 = User::factory()->student()->withPlan($plan)->create(['max_meetings' => 5]);
        $userWithoutTransactions = User::factory()->student()->withPlan($plan)->create(['max_meetings' => 0]);
        MeetingQuotaTransaction::factory()->consumed()->state(['user_id' => $user1->id])->create();
        MeetingQuotaTransaction::factory()->consumed()->state(['user_id' => $user2->id])->create();
        MeetingQuotaTransaction::factory()->adminGrant(User::factory()->admin()->create(), 3)
            ->state(['user_id' => $user2->id])
            ->create();

        $users = User::query()->whereIn('id', [
            $user1->id,
            $user2->id,
            $userWithoutTransactions->id,
        ])->get();

        DB::enableQueryLog();
        $remaining = app(MeetingQuotaService::class)->remainingForUsers($users);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([
            $user1->id => 11,
            $user2->id => 7,
            $userWithoutTransactions->id => 0,
        ], $remaining);
        $this->assertCount(1, $queries, '全ユーザーの面談回数集計は一括クエリで行う');
    }

    public function test_remaining_for_empty_user_collection_returns_empty_array_without_query(): void
    {
        DB::enableQueryLog();
        $remaining = app(MeetingQuotaService::class)->remainingForUsers(new Collection);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $remaining);
        $this->assertCount(0, $queries);
    }

    public function test_history_orders_by_occurred_at_desc(): void
    {
        $user = User::factory()->student()->create();
        $oldest = MeetingQuotaTransaction::factory()->grantedInitial()->state([
            'user_id' => $user->id,
            'occurred_at' => now()->subDays(3),
        ])->create();
        $middle = MeetingQuotaTransaction::factory()->grantedInitial()->state([
            'user_id' => $user->id,
            'occurred_at' => now()->subDays(2),
        ])->create();
        $newest = MeetingQuotaTransaction::factory()->grantedInitial()->state([
            'user_id' => $user->id,
            'occurred_at' => now()->subDay(),
        ])->create();

        $page = app(MeetingQuotaService::class)->history($user);

        $ids = $page->pluck('id')->all();
        $this->assertSame([$newest->id, $middle->id, $oldest->id], $ids);
    }

    public function test_history_filters_by_type(): void
    {
        $user = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        MeetingQuotaTransaction::factory()->grantedInitial()->state(['user_id' => $user->id])->create();
        $adminGrant = MeetingQuotaTransaction::factory()->adminGrant($admin, 2)->state(['user_id' => $user->id])->create();

        $page = app(MeetingQuotaService::class)->history($user, MeetingQuotaTransactionType::AdminGrant);

        $this->assertSame(1, $page->total());
        $this->assertSame($adminGrant->id, $page->first()->id);
    }

    public function test_history_isolates_users(): void
    {
        $user1 = User::factory()->student()->create();
        $user2 = User::factory()->student()->create();
        MeetingQuotaTransaction::factory()->grantedInitial()->count(3)->state(['user_id' => $user1->id])->create();
        MeetingQuotaTransaction::factory()->grantedInitial()->state(['user_id' => $user2->id])->create();

        $page = app(MeetingQuotaService::class)->history($user1);

        $this->assertSame(3, $page->total());
        foreach ($page->items() as $item) {
            $this->assertSame($user1->id, $item->user_id);
        }
    }

    public function test_history_eager_loads_granted_by_to_avoid_n_plus_one(): void
    {
        $user = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        // 10 件の admin_grant を作成
        MeetingQuotaTransaction::factory()->adminGrant($admin, 1)->count(10)
            ->state(['user_id' => $user->id])->create();

        DB::enableQueryLog();
        $page = app(MeetingQuotaService::class)->history($user);
        foreach ($page->items() as $tx) {
            // Eager Load 済みなので追加クエリは発生しないはず
            $tx->grantedBy?->name;
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // count + select の paginate 2 クエリ + grantedBy Eager Load 1 クエリ程度で済む想定。
        // N+1 が走ると transaction 件数分の追加クエリで 10+ になる。
        $this->assertLessThan(10, count($queries), '想定より多いクエリが発行された(N+1 の可能性)');
    }

    public function test_history_paginates_at_specified_perpage(): void
    {
        $user = User::factory()->student()->create();
        MeetingQuotaTransaction::factory()->grantedInitial()->count(25)->state(['user_id' => $user->id])->create();

        $page = app(MeetingQuotaService::class)->history($user, perPage: 10);

        $this->assertSame(25, $page->total());
        $this->assertCount(10, $page->items());
    }
}
