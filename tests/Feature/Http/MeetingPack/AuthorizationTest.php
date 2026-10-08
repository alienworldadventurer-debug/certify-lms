<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_before_missing_pack_is_resolved(): void
    {
        $this->get('/admin/meeting-packs/01m4bgk9rry9stfh17hhtqdvk1')
            ->assertRedirect('/login');
    }

    public function test_non_admin_roles_are_forbidden_for_existing_and_missing_pack_ids(): void
    {
        $pack = MeetingPack::factory()->create();
        $missingId = '01m4bgk9rry9stfh17hhtqdvk1';

        foreach ([User::factory()->student()->create(), User::factory()->coach()->create()] as $user) {
            $this->actingAs($user)
                ->get(route('admin.meeting-packs.index'))
                ->assertForbidden();
            $this->get(route('admin.meeting-packs.show', $pack))
                ->assertForbidden();
            $this->get('/admin/meeting-packs/' . $missingId)
                ->assertForbidden();
            $this->post('/admin/meeting-packs/' . $missingId . '/publish')
                ->assertForbidden();
        }
    }

    public function test_admin_gets_not_found_for_missing_pack(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/meeting-packs/01m4bgk9rry9stfh17hhtqdvk1')
            ->assertNotFound();
    }
}
