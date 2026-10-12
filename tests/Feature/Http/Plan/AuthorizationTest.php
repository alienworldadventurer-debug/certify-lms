<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_for_every_management_operation(): void
    {
        $plan = Plan::factory()->create();
        $missingId = '01m4bgk9rry9stfh17hhtqdvk1';
        $requests = [
            ['GET', route('admin.plans.index')],
            ['GET', route('admin.plans.create')],
            ['POST', route('admin.plans.store')],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->call($method, $uri)->assertRedirect('/login');
        }

        foreach ([$plan->id, $missingId] as $id) {
            foreach ($this->resourceRequests($id) as [$method, $uri]) {
                $this->call($method, $uri)->assertRedirect('/login');
            }
        }
    }

    public function test_non_admin_roles_are_forbidden_for_every_operation(): void
    {
        $plan = Plan::factory()->create();
        $users = [
            User::factory()->student()->create(),
            User::factory()->student()->withdrawn()->create(),
            User::factory()->student()->graduated()->create(),
            User::factory()->coach()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->get(route('admin.plans.index'))
                ->assertForbidden();
            $this->get(route('admin.plans.create'))->assertForbidden();
            $this->post(route('admin.plans.store'))->assertForbidden();

            foreach ($this->resourceRequests($plan->id) as [$method, $uri]) {
                $this->call($method, $uri)->assertForbidden();
            }
        }

        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    public function test_admin_gets_not_found_for_missing_plans(): void
    {
        $admin = User::factory()->admin()->create();
        $missingId = '01m4bgk9rry9stfh17hhtqdvk1';

        foreach ($this->resourceRequests($missingId) as [$method, $uri]) {
            $this->actingAs($admin)->call($method, $uri)->assertNotFound();
        }
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function resourceRequests(string $id): array
    {
        return [
            ['GET', route('admin.plans.show', $id)],
            ['GET', route('admin.plans.edit', $id)],
            ['PUT', route('admin.plans.update', $id)],
            ['DELETE', route('admin.plans.destroy', $id)],
            ['POST', route('admin.plans.publish', $id)],
            ['POST', route('admin.plans.archive', $id)],
            ['POST', route('admin.plans.unarchive', $id)],
        ];
    }
}
