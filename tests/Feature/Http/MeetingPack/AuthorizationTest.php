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

    public function test_guest_is_redirected_to_login_for_every_management_operation(): void
    {
        $pack = MeetingPack::factory()->create();
        $missingId = '01m4bgk9rry9stfh17hhtqdvk1';
        $requests = [
            ['GET', route('admin.meeting-packs.index')],
            ['GET', route('admin.meeting-packs.create')],
            ['POST', route('admin.meeting-packs.store')],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->call($method, $uri)->assertRedirect('/login');
        }

        foreach ([$pack->id, $missingId] as $id) {
            $resourceRequests = [
                ['GET', route('admin.meeting-packs.show', $id)],
                ['GET', route('admin.meeting-packs.edit', $id)],
                ['PATCH', route('admin.meeting-packs.update', $id)],
                ['DELETE', route('admin.meeting-packs.destroy', $id)],
                ['POST', route('admin.meeting-packs.publish', $id)],
                ['POST', route('admin.meeting-packs.archive', $id)],
                ['POST', route('admin.meeting-packs.unarchive', $id)],
            ];

            foreach ($resourceRequests as [$method, $uri]) {
                $this->call($method, $uri)->assertRedirect('/login');
            }
        }
    }

    public function test_non_admin_roles_are_forbidden_for_every_operation_and_resource_id(): void
    {
        $pack = MeetingPack::factory()->create();
        $missingId = '01m4bgk9rry9stfh17hhtqdvk1';
        $users = [
            User::factory()->student()->create(),
            User::factory()->student()->withdrawn()->create(),
            User::factory()->student()->graduated()->create(),
            User::factory()->coach()->create(),
        ];

        foreach ($users as $user) {
            foreach ([$pack->id, $missingId] as $id) {
                $resourceRequests = [
                    ['GET', route('admin.meeting-packs.show', $id)],
                    ['GET', route('admin.meeting-packs.edit', $id)],
                    ['PATCH', route('admin.meeting-packs.update', $id)],
                    ['DELETE', route('admin.meeting-packs.destroy', $id)],
                    ['POST', route('admin.meeting-packs.publish', $id)],
                    ['POST', route('admin.meeting-packs.archive', $id)],
                    ['POST', route('admin.meeting-packs.unarchive', $id)],
                ];

                foreach ($resourceRequests as [$method, $uri]) {
                    $this->actingAs($user)->call($method, $uri)->assertForbidden();
                }
            }

            $this->actingAs($user)->get(route('admin.meeting-packs.index'))->assertForbidden();
            $this->get(route('admin.meeting-packs.create'))->assertForbidden();
            $this->post(route('admin.meeting-packs.store'))->assertForbidden();
        }

        $this->assertDatabaseHas('meeting_packs', ['id' => $pack->id]);
    }

    public function test_admin_gets_not_found_for_missing_pack_on_resource_operations(): void
    {
        $admin = User::factory()->admin()->create();
        $missingId = '01m4bgk9rry9stfh17hhtqdvk1';
        $requests = [
            ['GET', route('admin.meeting-packs.show', $missingId)],
            ['GET', route('admin.meeting-packs.edit', $missingId)],
            ['PATCH', route('admin.meeting-packs.update', $missingId)],
            ['DELETE', route('admin.meeting-packs.destroy', $missingId)],
            ['POST', route('admin.meeting-packs.publish', $missingId)],
            ['POST', route('admin.meeting-packs.archive', $missingId)],
            ['POST', route('admin.meeting-packs.unarchive', $missingId)],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->actingAs($admin)->call($method, $uri)->assertNotFound();
        }
    }
}
