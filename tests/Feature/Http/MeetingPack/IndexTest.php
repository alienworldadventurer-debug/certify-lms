<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_keyword_and_status_and_trims_keyword(): void
    {
        $admin = User::factory()->admin()->create();
        $match = MeetingPack::factory()->published()->create(['name' => 'Premium Coaching']);
        MeetingPack::factory()->draft()->create(['name' => 'Premium Draft']);
        MeetingPack::factory()->published()->create(['name' => 'Other Pack']);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', ['keyword' => '  Premium  ', 'status' => 'published']));

        $response->assertOk();
        $response->assertViewHas('plans', function ($plans) use ($match): bool {
            return $plans->total() === 1 && $plans->first()->is($match);
        });
        $response->assertViewHas('keyword', 'Premium');
        $response->assertViewHas('status', 'published');
    }

    public function test_index_orders_published_first_then_sort_order_and_newest(): void
    {
        $admin = User::factory()->admin()->create();
        $olderPublished = MeetingPack::factory()->published()->create([
            'sort_order' => 5,
            'created_at' => now()->subMinute(),
        ]);
        $newerPublished = MeetingPack::factory()->published()->create([
            'sort_order' => 5,
            'created_at' => now(),
        ]);
        $firstDraft = MeetingPack::factory()->draft()->create(['sort_order' => 0]);
        $secondDraft = MeetingPack::factory()->draft()->create(['sort_order' => 1]);

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index'));

        $response->assertViewHas('plans', function ($plans) use (
            $newerPublished,
            $olderPublished,
            $firstDraft,
            $secondDraft,
        ): bool {
            return $plans->getCollection()->modelKeys() === [
                $newerPublished->id,
                $olderPublished->id,
                $firstDraft->id,
                $secondDraft->id,
            ];
        });
    }

    public function test_index_has_twenty_items_per_page_and_preserves_query_on_next_page(): void
    {
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->count(21)->published()->create(['name' => 'Needle Pack']);

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index', [
            'keyword' => 'Needle',
            'status' => MeetingPackStatus::Published->value,
        ]));

        $response->assertViewHas('plans', function ($plans): bool {
            return $plans->perPage() === 20
                && $plans->count() === 20
                && str_contains($plans->nextPageUrl(), 'keyword=Needle')
                && str_contains($plans->nextPageUrl(), 'status=published');
        });
    }

    public function test_invalid_status_is_removed_while_keyword_is_preserved(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index', [
            'keyword' => '  Keep This  ',
            'status' => 'invalid',
        ]));

        $response->assertRedirect(route('admin.meeting-packs.index', ['keyword' => 'Keep This']));
        $response->assertSessionHas('warning', '指定されたステータスは無効です。ステータス条件を適用せずに表示しています。');
    }

    public function test_search_keyword_has_no_server_side_length_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $keyword = str_repeat('x', 150);

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index', [
            'keyword' => $keyword,
        ]));

        $response->assertOk();
        $response->assertViewHas('keyword', $keyword);
    }

    public function test_whitespace_only_search_keyword_is_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->published()->create();

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index', [
            'keyword' => '     ',
        ]));

        $response->assertOk();
        $response->assertViewHas('keyword', '');
        $response->assertViewHas('plans', fn ($plans): bool => $plans->total() === 1);
    }
}
