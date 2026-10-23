<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings\GoogleCalendar;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleCalendarConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const FLASH_FAILED = 'Googleカレンダーとの連携に失敗しました。';

    private const FLASH_DISCONNECTED = 'Googleカレンダーとの連携を解除しました。';

    public function test_student_cannot_start_google_connection(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('settings.google-calendar.redirect'))
            ->assertForbidden();
    }

    public function test_admin_cannot_disconnect_google_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        GoogleCredential::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->delete(route('settings.google-calendar.destroy'))
            ->assertForbidden();

        $this->assertDatabaseHas('google_credentials', ['user_id' => $admin->id]);
    }

    public function test_connected_coach_is_redirected_without_leaving_the_app(): void
    {
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->forCoach($coach)->create();

        $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'))
            ->assertRedirect('/settings/availability');
    }

    public function test_callback_with_error_flashes_failure_message(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->withSession(['google_calendar_oauth_state' => 'expected-state'])
            ->get(route('settings.google-calendar.callback', ['error' => 'access_denied']))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('error', self::FLASH_FAILED);

        $this->assertDatabaseMissing('google_credentials', ['user_id' => $coach->id]);
    }

    public function test_callback_with_mismatched_state_flashes_failure_message(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->withSession(['google_calendar_oauth_state' => 'expected-state'])
            ->get(route('settings.google-calendar.callback', ['code' => 'dummy-code', 'state' => 'wrong-state']))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('error', self::FLASH_FAILED);

        $this->assertDatabaseMissing('google_credentials', ['user_id' => $coach->id]);
    }

    public function test_coach_can_disconnect_and_flashes_success(): void
    {
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->forCoach($coach)->create();

        $this->actingAs($coach)
            ->delete(route('settings.google-calendar.destroy'))
            ->assertRedirect(route('settings.availability.index'))
            ->assertSessionHas('success', self::FLASH_DISCONNECTED);

        $this->assertDatabaseMissing('google_credentials', ['user_id' => $coach->id]);
    }

    public function test_guest_is_redirected_to_login_from_all_google_routes(): void
    {
        $this->get(route('settings.google-calendar.redirect'))->assertRedirect(route('login'));
        $this->get(route('settings.google-calendar.callback'))->assertRedirect(route('login'));
        $this->delete(route('settings.google-calendar.destroy'))->assertRedirect(route('login'));
    }

    public function test_student_and_admin_cannot_call_callback(): void
    {
        foreach ([User::factory()->student()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)
                ->withSession(['google_calendar_oauth_state' => 'expected-state'])
                ->get(route('settings.google-calendar.callback', ['code' => 'dummy-code', 'state' => 'expected-state']))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('google_credentials', 0);
    }

    public function test_disconnect_without_credential_still_flashes_success(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->delete(route('settings.google-calendar.destroy'))
            ->assertRedirect(route('settings.availability.index'))
            ->assertSessionHas('success', self::FLASH_DISCONNECTED);
    }

    public function test_callback_without_code_flashes_failure_and_returns_to_availability(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->withSession(['google_calendar_oauth_state' => 'expected-state'])
            ->get(route('settings.google-calendar.callback', ['state' => 'expected-state']))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('error', self::FLASH_FAILED);

        $this->assertDatabaseMissing('google_credentials', ['user_id' => $coach->id]);
    }

    public function test_callback_error_redirects_to_availability_and_clears_state(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->withSession(['google_calendar_oauth_state' => 'expected-state'])
            ->get(route('settings.google-calendar.callback', ['error' => 'access_denied']))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('error', self::FLASH_FAILED)
            ->assertSessionMissing('google_calendar_oauth_state');
    }

    public function test_reused_state_is_rejected_on_second_callback(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->withSession(['google_calendar_oauth_state' => 'expected-state'])
            ->get(route('settings.google-calendar.callback', ['error' => 'access_denied']))
            ->assertSessionHas('error', self::FLASH_FAILED);

        $this->actingAs($coach)
            ->get(route('settings.google-calendar.callback', ['code' => 'dummy-code', 'state' => 'expected-state']))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('error', self::FLASH_FAILED);

        $this->assertDatabaseMissing('google_credentials', ['user_id' => $coach->id]);
    }

    public function test_external_redirect_path_is_ignored(): void
    {
        $coach = User::factory()->coach()->create();

        foreach (['https://evil.example', '//evil.example', '\\\\evil.example'] as $path) {
            $this->actingAs($coach)
                ->get(route('settings.google-calendar.redirect', ['redirect_path' => $path]))
                ->assertSessionHas('google_calendar_oauth_redirect_path', '/settings/availability');
        }
    }
}
