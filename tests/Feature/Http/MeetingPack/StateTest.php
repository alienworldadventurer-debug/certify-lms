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
        $pack = MeetingPack::factory()->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.publish', $pack))
            ->assertRedirect(route('admin.meeting-packs.show', $pack))
            ->assertSessionHas('success', '面談パックを公開しました。');
        $this->assertSame(MeetingPackStatus::Published, $pack->fresh()->status);
        $this->assertSame($admin->id, $pack->fresh()->updated_by_user_id);

        $this->post(route('admin.meeting-packs.archive', $pack))
            ->assertSessionHas('success', '面談パックをアーカイブしました。');
        $this->assertSame(MeetingPackStatus::Archived, $pack->fresh()->status);

        $this->post(route('admin.meeting-packs.unarchive', $pack))
            ->assertSessionHas('success', '面談パックを下書きに戻しました。');
        $this->assertSame(MeetingPackStatus::Draft, $pack->fresh()->status);
    }

    public function test_disallowed_transitions_keep_status_and_metadata_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $cases = [
            [MeetingPackStatus::Draft, 'archive', '下書きの面談パックは公開のみ可能です。'],
            [MeetingPackStatus::Published, 'unarchive', '公開中の面談パックはアーカイブのみ可能です。'],
            [MeetingPackStatus::Archived, 'publish', 'アーカイブ済みの面談パックは下書きに戻すことのみ可能です。'],
        ];

        foreach ($cases as [$status, $operation, $message]) {
            $pack = MeetingPack::factory()->create([
                'status' => $status,
            ]);
            $originalUpdatedAt = $pack->updated_at->toDateTimeString();
            $originalUpdatedBy = $pack->updated_by_user_id;

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
