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
            ->assertSessionHas('error', self::FLASH_FAILED);

        $this->assertDatabaseMissing('google_credentials', ['user_id' => $coach->id]);
    }

    public function test_callback_with_mismatched_state_flashes_failure_message(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->withSession(['google_calendar_oauth_state' => 'expected-state'])
            ->get(route('settings.google-calendar.callback', ['code' => 'dummy-code', 'state' => 'wrong-state']))
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
}
