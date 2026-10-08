<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_draft_with_trimmed_values_and_default_order(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.meeting-packs.store'), [
            'name' => '  5 回パック  ',
            'description' => '  面談パックの説明  ',
            'meeting_count' => 5,
            'price' => 0,
            'stripe_price_id' => '  price_example  ',
        ]);

        $pack = MeetingPack::query()->firstOrFail();

        $response->assertRedirect(route('admin.meeting-packs.show', $pack));
        $response->assertSessionHas('success', '面談パックを作成しました。');
        $this->assertSame('5 回パック', $pack->name);
        $this->assertSame('面談パックの説明', $pack->description);
        $this->assertSame('price_example', $pack->stripe_price_id);
        $this->assertSame(0, $pack->sort_order);
        $this->assertSame(0, $pack->price);
        $this->assertSame(MeetingPackStatus::Draft, $pack->status);
        $this->assertSame($admin->id, $pack->created_by_user_id);
        $this->assertSame($admin->id, $pack->updated_by_user_id);
    }

    public function test_admin_can_create_with_maximum_field_boundaries(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = [
            'name' => str_repeat('a', 100),
            'description' => str_repeat('b', 2000),
            'meeting_count' => 100,
            'price' => 1000000,
            'stripe_price_id' => str_repeat('c', 255),
            'sort_order' => 1000000,
        ];

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $payload);

        $response->assertRedirect();
        $this->assertDatabaseHas('meeting_packs', [
            'name' => $payload['name'],
            'meeting_count' => 100,
            'price' => 1000000,
            'sort_order' => 1000000,
        ]);
    }

    public function test_admin_can_view_detail_with_metadata_and_empty_purchase_history(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->create([
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.show', $pack));

        $response->assertOk();
        $response->assertSee('購入数');
        $response->assertSee('この SKU の購入はまだありません。');
        $response->assertSee($admin->name);
        $response->assertSee($pack->created_at->format('Y-m-d H:i'));
    }

    public function test_admin_can_open_create_and_edit_forms(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.meeting-packs.create'))
            ->assertOk()
            ->assertSee('面談パックの新規作成');

        $this->get(route('admin.meeting-packs.edit', $pack))
            ->assertOk()
            ->assertSee($pack->name);
    }

    public function test_required_values_return_requirement_messages(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->from(route('admin.meeting-packs.create'))
            ->post(route('admin.meeting-packs.store'), [
                'name' => '   ',
                'meeting_count' => '',
                'price' => '',
            ]);

        $response->assertSessionHasErrors([
            'name' => 'パック名は必須です。',
            'meeting_count' => '面談回数は必須です。',
            'price' => '価格は必須です。',
        ]);
        $this->assertDatabaseCount('meeting_packs', 0);
    }

    public function test_invalid_boundaries_return_requirement_messages(): void
    {
        $admin = User::factory()->admin()->create();
        $valid = [
            'name' => '検証用パック',
            'description' => null,
            'meeting_count' => 1,
            'price' => 0,
            'stripe_price_id' => null,
            'sort_order' => 0,
        ];
        $invalidCases = [
            ['name', str_repeat('a', 101), 'パック名は100文字以内で入力してください。'],
            ['description', str_repeat('a', 2001), '説明は2,000文字以内で入力してください。'],
            ['stripe_price_id', str_repeat('a', 256), 'Stripe Price IDは255文字以内で入力してください。'],
            ['meeting_count', 0, '面談回数は1〜100の整数で入力してください。'],
            ['meeting_count', 101, '面談回数は1〜100の整数で入力してください。'],
            ['meeting_count', '1.5', '面談回数は1〜100の整数で入力してください。'],
            ['price', -1, '価格は0〜1,000,000円の整数で入力してください。'],
            ['price', 1000001, '価格は0〜1,000,000円の整数で入力してください。'],
            ['price', '1.5', '価格は0〜1,000,000円の整数で入力してください。'],
            ['sort_order', -1, '並び順は0〜1,000,000の整数で入力してください。'],
            ['sort_order', 1000001, '並び順は0〜1,000,000の整数で入力してください。'],
            ['sort_order', '1.5', '並び順は0〜1,000,000の整数で入力してください。'],
        ];

        foreach ($invalidCases as [$field, $value, $message]) {
            $response = $this->actingAs($admin)
                ->from(route('admin.meeting-packs.create'))
                ->post(route('admin.meeting-packs.store'), [...$valid, $field => $value]);

            $response->assertSessionHasErrors([$field => $message]);
        }

        $this->assertDatabaseCount('meeting_packs', 0);
    }

    public function test_admin_can_update_any_status_without_changing_status_or_creator(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->admin()->create();
        $payload = [
            'name' => '  更新後  ',
            'description' => '  説明  ',
            'meeting_count' => 10,
            'price' => 5000,
            'stripe_price_id' => '  price_new  ',
            'sort_order' => 20,
        ];

        foreach (MeetingPackStatus::cases() as $status) {
            $pack = MeetingPack::factory()->create([
                'status' => $status,
                'created_by_user_id' => $creator->id,
                'updated_by_user_id' => $creator->id,
                'name' => '更新前',
                'description' => null,
                'meeting_count' => 1,
                'price' => 0,
                'stripe_price_id' => null,
                'sort_order' => 0,
            ]);
            $createdAt = $pack->created_at;
            $updatedAt = $pack->updated_at;
            $this->travel(1)->seconds();

            $response = $this->actingAs($admin)
                ->patch(route('admin.meeting-packs.update', $pack), $payload);

            $pack->refresh();
            $response->assertRedirect(route('admin.meeting-packs.show', $pack));
            $response->assertSessionHas('success', '面談パックを更新しました。');
            $this->assertSame('更新後', $pack->name);
            $this->assertSame('説明', $pack->description);
            $this->assertSame(10, $pack->meeting_count);
            $this->assertSame(5000, $pack->price);
            $this->assertSame('price_new', $pack->stripe_price_id);
            $this->assertSame(20, $pack->sort_order);
            $this->assertSame($status, $pack->status);
            $this->assertSame($creator->id, $pack->created_by_user_id);
            $this->assertSame($admin->id, $pack->updated_by_user_id);
            $this->assertSame($createdAt->toDateTimeString(), $pack->created_at->toDateTimeString());
            $this->assertTrue($pack->updated_at->greaterThan($updatedAt));
        }
    }

    public function test_update_records_updated_at_when_submitting_unchanged_values(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->create([
            'name' => '同じ値',
            'description' => '説明',
            'meeting_count' => 5,
            'price' => 1000,
            'stripe_price_id' => 'price_same',
            'sort_order' => 10,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
        $originalUpdatedAt = $pack->updated_at;
        $this->travel(2)->seconds();

        $response = $this->actingAs($admin)->patch(route('admin.meeting-packs.update', $pack), [
            'name' => $pack->name,
            'description' => $pack->description,
            'meeting_count' => $pack->meeting_count,
            'price' => $pack->price,
            'stripe_price_id' => $pack->stripe_price_id,
            'sort_order' => $pack->sort_order,
        ]);

        $response->assertRedirect(route('admin.meeting-packs.show', $pack));
        $response->assertSessionHas('success', '面談パックを更新しました。');
        $this->assertTrue($pack->fresh()->updated_at->greaterThan($originalUpdatedAt));
    }

    public function test_update_rejects_invalid_data_and_preserves_existing_values(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->create([
            'name' => '変更前',
            'price' => 1000,
        ]);
        $valid = [
            'name' => '有効な名前',
            'description' => null,
            'meeting_count' => 1,
            'price' => 1000,
            'stripe_price_id' => null,
            'sort_order' => 0,
        ];
        $invalidCases = [
            ['name', '   ', 'パック名は必須です。'],
            ['name', str_repeat('a', 101), 'パック名は100文字以内で入力してください。'],
            ['description', str_repeat('a', 2001), '説明は2,000文字以内で入力してください。'],
            ['meeting_count', '', '面談回数は必須です。'],
            ['meeting_count', '1.5', '面談回数は1〜100の整数で入力してください。'],
            ['price', 1000001, '価格は0〜1,000,000円の整数で入力してください。'],
            ['price', '1.5', '価格は0〜1,000,000円の整数で入力してください。'],
            ['stripe_price_id', str_repeat('a', 256), 'Stripe Price IDは255文字以内で入力してください。'],
            ['sort_order', -1, '並び順は0〜1,000,000の整数で入力してください。'],
        ];

        foreach ($invalidCases as [$field, $value, $message]) {
            $response = $this->actingAs($admin)
                ->from(route('admin.meeting-packs.edit', $pack))
                ->patch(route('admin.meeting-packs.update', $pack), [...$valid, $field => $value]);

            $response->assertSessionHasErrors([$field => $message]);
        }

        $response->assertSessionHas('_old_input.name', '有効な名前');
        $this->assertSame('変更前', $pack->fresh()->name);
        $this->assertSame(1000, $pack->fresh()->price);
    }

    public function test_admin_can_physically_delete_draft_and_archived_packs(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = MeetingPack::factory()->draft()->create();
        $archived = MeetingPack::factory()->archived()->create();

        $this->actingAs($admin)
            ->delete(route('admin.meeting-packs.destroy', $draft))
            ->assertRedirect(route('admin.meeting-packs.index'))
            ->assertSessionHas('success', '面談パックを削除しました。');

        $this->actingAs($admin)
            ->delete(route('admin.meeting-packs.destroy', $archived))
            ->assertRedirect(route('admin.meeting-packs.index'));

        $this->assertDatabaseMissing('meeting_packs', ['id' => $draft->id]);
        $this->assertDatabaseMissing('meeting_packs', ['id' => $archived->id]);
    }

    public function test_admin_cannot_delete_published_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->published()->create();

        $this->actingAs($admin)
            ->delete(route('admin.meeting-packs.destroy', $pack))
            ->assertRedirect(route('admin.meeting-packs.show', $pack))
            ->assertSessionHas('error', '公開中の面談パックは削除できません。');

        $this->assertDatabaseHas('meeting_packs', ['id' => $pack->id]);
    }
}
