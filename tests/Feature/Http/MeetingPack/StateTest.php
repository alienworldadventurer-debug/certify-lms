<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
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
        $pack = MeetingPack::factory()->draft()->create([
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);
        $createdAt = $pack->created_at;
        $transitions = [
            ['publish', MeetingPackStatus::Published, '面談パックを公開しました。'],
            ['archive', MeetingPackStatus::Archived, '面談パックをアーカイブしました。'],
            ['unarchive', MeetingPackStatus::Draft, '面談パックを下書きに戻しました。'],
        ];

        foreach ($transitions as [$operation, $expectedStatus, $message]) {
            $previousUpdatedAt = $pack->fresh()->updated_at;
            $this->travel(1)->seconds();

            $this->actingAs($admin)
                ->post(route('admin.meeting-packs.'.$operation, $pack))
                ->assertRedirect(route('admin.meeting-packs.show', $pack))
                ->assertSessionHas('success', $message);

            $pack->refresh();
            $this->assertSame($expectedStatus, $pack->status);
            $this->assertSame($creator->id, $pack->created_by_user_id);
            $this->assertSame($admin->id, $pack->updated_by_user_id);
            $this->assertSame($createdAt->toDateTimeString(), $pack->created_at->toDateTimeString());
            $this->assertTrue($pack->updated_at->greaterThan($previousUpdatedAt));
        }
    }

    public function test_disallowed_transitions_keep_status_and_metadata_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $cases = [
            [MeetingPackStatus::Draft, 'unarchive', '下書きの面談パックは公開のみ可能です。'],
            [MeetingPackStatus::Draft, 'archive', '下書きの面談パックは公開のみ可能です。'],
            [MeetingPackStatus::Published, 'publish', '公開中の面談パックはアーカイブのみ可能です。'],
            [MeetingPackStatus::Published, 'unarchive', '公開中の面談パックはアーカイブのみ可能です。'],
            [MeetingPackStatus::Archived, 'archive', 'アーカイブ済みの面談パックは下書きに戻すことのみ可能です。'],
            [MeetingPackStatus::Archived, 'publish', 'アーカイブ済みの面談パックは下書きに戻すことのみ可能です。'],
        ];

        foreach ($cases as [$status, $operation, $message]) {
            $pack = MeetingPack::factory()->create([
                'status' => $status,
            ]);
            $originalUpdatedAt = $pack->updated_at->toDateTimeString();
            $originalUpdatedBy = $pack->updated_by_user_id;
            $this->travel(1)->seconds();

            $response = $this->actingAs($admin)
                ->post(route('admin.meeting-packs.'.$operation, $pack));

            $response->assertRedirect(route('admin.meeting-packs.show', $pack));
            $response->assertSessionHas('error', $message);
            $this->assertSame($status, $pack->fresh()->status);
            $this->assertSame($originalUpdatedBy, $pack->fresh()->updated_by_user_id);
            $this->assertSame($originalUpdatedAt, $pack->fresh()->updated_at->toDateTimeString());
        }
    }
}
