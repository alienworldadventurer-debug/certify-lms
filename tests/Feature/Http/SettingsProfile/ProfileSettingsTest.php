<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Enums\UserRole;
use App\Models\User;
use App\UseCases\Settings\DeleteAvatarAction;
use App\UseCases\Settings\StoreAvatarAction;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_roles_and_graduated_students_can_view_settings(): void
    {
        $users = [
            User::factory()->student()->create(),
            User::factory()->student()->graduated()->create(),
            User::factory()->coach()->create(),
            User::factory()->admin()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->get(route('settings.profile.edit'))
                ->assertOk()
                ->assertViewIs('settings.profile');
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.profile.edit'))
            ->assertRedirect('/login');
    }

    public function test_missing_or_invalid_tab_defaults_to_profile(): void
    {
        $user = User::factory()->student()->create();

        foreach ([[], ['tab' => 'invalid']] as $query) {
            $this->actingAs($user)
                ->get(route('settings.profile.edit', $query))
                ->assertOk()
                ->assertSee('name="name"', false)
                ->assertDontSee('name="current_password"', false);
        }
    }

    public function test_coach_only_meeting_url_field_is_rendered_for_coaches(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($coach)
            ->get(route('settings.profile.edit'))
            ->assertOk()
            ->assertSee('name="meeting_url"', false);

        foreach ([$student, $admin] as $user) {
            Auth::logout();
            $this->app['session']->flush();

            $this->actingAs($user)
                ->get(route('settings.profile.edit'))
                ->assertOk()
                ->assertDontSee('name="meeting_url"', false);
        }
    }

    public function test_profile_update_trims_values_and_accepts_maximum_lengths(): void
    {
        $coach = User::factory()->coach()->create();
        $meetingUrl = 'https://example.com/'.str_repeat('c', 480);
        $this->assertSame(500, mb_strlen($meetingUrl));

        $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => "\u{3000}".str_repeat('a', 50)."\u{3000}",
                'bio' => "\u{3000}".str_repeat('b', 1000)."\u{3000}",
                'meeting_url' => "\u{3000}".$meetingUrl."\u{3000}",
                'email' => 'changed@example.com',
                'role' => 'student',
                'status' => 'withdrawn',
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHas('success', 'プロフィールを更新しました。');

        $this->assertSame(str_repeat('a', 50), $coach->fresh()->name);
        $this->assertSame(str_repeat('b', 1000), $coach->fresh()->bio);
        $this->assertSame($meetingUrl, $coach->fresh()->meeting_url);
        $this->assertSame($coach->email, $coach->fresh()->email);
        $this->assertSame(UserRole::Coach, $coach->fresh()->role);
        $this->assertSame($coach->status, $coach->fresh()->status);
    }

    public function test_meeting_url_rejects_501_characters_but_accepts_500(): void
    {
        $coach = User::factory()->coach()->create();
        $url = 'https://example.com/'.str_repeat('c', 480);
        $this->assertSame(500, mb_strlen($url));

        $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => 'Coach',
                'meeting_url' => $url.'c',
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHasErrors([
                'meeting_url' => '固定面談URLは500文字以内で入力してください。',
            ]);

        $this->assertNull($coach->fresh()->meeting_url);

        $this->patch(route('settings.profile.update'), [
            'name' => 'Coach',
            'meeting_url' => $url,
        ])->assertSessionHasNoErrors();

        $this->assertSame($url, $coach->fresh()->meeting_url);
    }

    public function test_profile_update_is_available_to_all_roles_including_graduated_students(): void
    {
        $users = [
            User::factory()->student()->create(),
            User::factory()->student()->graduated()->create(),
            User::factory()->coach()->create(),
            User::factory()->admin()->create(),
        ];

        foreach ($users as $user) {
            Auth::logout();
            $this->app['session']->flush();

            $payload = ['name' => '更新後の氏名', 'bio' => '更新後の自己紹介'];
            if ($user->role->value === 'coach') {
                $payload['meeting_url'] = 'https://example.com/coach-room';
            }

            $this->actingAs($user)
                ->patch(route('settings.profile.update'), $payload)
                ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']));

            $this->assertSame('更新後の氏名', $user->fresh()->name);
            $this->assertSame('更新後の自己紹介', $user->fresh()->bio);
        }
    }

    public function test_blank_name_is_rejected_and_blank_optional_values_are_cleared(): void
    {
        $coach = User::factory()->coach()->create([
            'bio' => '既存の紹介',
            'meeting_url' => 'https://example.com/meeting',
        ]);

        $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => "\u{3000}\t",
                'bio' => '入力し直した紹介',
                'meeting_url' => '',
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHasErrors(['name' => '氏名を入力してください。']);

        $this->assertSame('既存の紹介', $coach->fresh()->bio);
        $this->assertSame('https://example.com/meeting', $coach->fresh()->meeting_url);

        $this->actingAs($coach->fresh())
            ->patch(route('settings.profile.update'), [
                'name' => '更新後',
                'bio' => "\u{3000}\t",
                'meeting_url' => "\u{3000}\t",
            ])
            ->assertSessionHas('success', 'プロフィールを更新しました。');

        $this->assertNull($coach->fresh()->bio);
        $this->assertNull($coach->fresh()->meeting_url);
    }

    public function test_profile_validation_messages_and_invalid_url(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => str_repeat('a', 51),
                'bio' => str_repeat('b', 1001),
                'meeting_url' => 'ftp://example.com',
            ])
            ->assertSessionHasErrors([
                'name' => '氏名は50文字以内で入力してください。',
                'bio' => '自己紹介は1000文字以内で入力してください。',
                'meeting_url' => '固定面談URLを正しい形式で入力してください。',
            ]);

        $this->assertSame($coach->name, $coach->fresh()->name);
    }

    public function test_profile_validation_redirect_renders_profile_tab_errors_and_old_values(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => '送信した氏名',
                'bio' => '送信した自己紹介',
                'meeting_url' => '不正URL',
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']));

        $this->get(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertOk()
            ->assertSee('aria-selected="true"', false)
            ->assertSee('name="name"', false)
            ->assertDontSee('name="current_password"', false)
            ->assertSee('value="送信した氏名"', false)
            ->assertSee('送信した自己紹介')
            ->assertSee('value="不正URL"', false)
            ->assertSee('固定面談URLを正しい形式で入力してください。');
    }

    public function test_non_string_profile_values_show_validation_errors_without_breaking_redirected_page(): void
    {
        $coach = User::factory()->coach()->create([
            'name' => 'Original name',
            'bio' => 'Original bio',
            'meeting_url' => 'https://example.com/original',
        ]);
        $invalidValues = [
            'name' => [
                ['invalid'],
                '氏名は文字列で入力してください。',
            ],
            'bio' => [
                ['invalid'],
                '自己紹介は文字列で入力してください。',
            ],
            'meeting_url' => [
                ['invalid'],
                '固定面談URLは文字列で入力してください。',
            ],
        ];
        $this->actingAs($coach);

        foreach ($invalidValues as $field => [$value, $message]) {
            $this->patch(route('settings.profile.update'), [
                'name' => 'Updated name',
                'bio' => 'Updated bio',
                'meeting_url' => 'https://example.com/updated',
                $field => $value,
            ])
                ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
                ->assertSessionHasErrors([$field => $message]);

            $this->get(route('settings.profile.edit', ['tab' => 'profile']))
                ->assertOk()
                ->assertSee($message);
        }

        $this->assertSame('Original name', $coach->fresh()->name);
        $this->assertSame('Original bio', $coach->fresh()->bio);
        $this->assertSame('https://example.com/original', $coach->fresh()->meeting_url);
    }

    public function test_non_coach_cannot_submit_meeting_url(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->patch(route('settings.profile.update'), [
                'name' => '変更しない',
                'bio' => '変更しない',
                'meeting_url' => 'https://example.com/unauthorized',
            ])
            ->assertForbidden();

        $this->assertSame($student->name, $student->fresh()->name);
        $this->assertNull($student->fresh()->meeting_url);
    }

    public function test_avatar_upload_replaces_old_file_only_after_new_upload(): void
    {
        Storage::fake('public');
        $oldPath = 'avatars/old.png';
        Storage::disk('public')->put($oldPath, 'old avatar');

        $user = User::factory()->student()->create([
            'avatar_url' => Storage::disk('public')->url($oldPath),
        ]);

        $this->actingAs($user)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('new.webp')->mimeType('image/webp'),
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHas('success', 'アバター画像を更新しました。');

        $newUrl = $user->fresh()->avatar_url;
        $this->assertNotSame(Storage::disk('public')->url($oldPath), $newUrl);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($this->avatarPathFromUrl($newUrl));
    }

    public function test_avatar_delete_uses_latest_image_when_request_has_a_stale_user_model(): void
    {
        Storage::fake('public');
        $oldPath = 'avatars/old.png';
        Storage::disk('public')->put($oldPath, 'old avatar');
        $user = User::factory()->student()->create([
            'avatar_url' => Storage::disk('public')->url($oldPath),
        ]);
        $staleUser = $user->fresh();

        app(StoreAvatarAction::class)(
            $user,
            UploadedFile::fake()->image('new.png'),
        );
        $newPath = $this->avatarPathFromUrl($user->fresh()->avatar_url);

        app(DeleteAvatarAction::class)($staleUser);

        $this->assertNull($user->fresh()->avatar_url);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_avatar_path_resolution_ignores_host_and_scheme_changes(): void
    {
        Storage::fake('public');
        $oldPath = 'avatars/old.png';
        Storage::disk('public')->put($oldPath, 'old avatar');
        $oldUrl = Storage::disk('public')->url($oldPath);
        $oldUrl = preg_replace('#^https?://[^/]+#', 'https://legacy.example.test', $oldUrl);
        $user = User::factory()->student()->create(['avatar_url' => $oldUrl]);

        $this->actingAs($user)
            ->delete(route('settings.avatar.destroy'))
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHas('success', 'アバター画像を削除しました。');

        Storage::disk('public')->assertMissing($oldPath);
        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_avatar_upload_rejects_missing_invalid_and_oversized_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->student()->create();

        $this->actingAs($user)
            ->post(route('settings.avatar.store'))
            ->assertSessionHasErrors(['avatar' => '画像を選択してください。']);

        $this->actingAs($user)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->create('image.svg', 10, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors(['avatar' => 'PNG／JPG／WebP形式の画像を選択してください。']);

        $this->actingAs($user)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->create('large.png', 2049, 'image/png'),
            ])
            ->assertSessionHasErrors(['avatar' => '2MB以内の画像を選択してください。']);

        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_jpeg_at_size_limit_and_large_dimensions_are_accepted(): void
    {
        Storage::fake('public');
        $user = User::factory()->student()->create();

        $this->actingAs($user)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('large.jpg', 4000, 3000)->size(2048),
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHas('success', 'アバター画像を更新しました。');

        $this->assertNotNull($user->fresh()->avatar_url);
        Storage::disk('public')->assertExists($this->avatarPathFromUrl($user->fresh()->avatar_url));
    }

    public function test_avatar_upload_failure_keeps_existing_profile_image(): void
    {
        $oldUrl = 'http://localhost/storage/avatars/old.png';
        $user = User::factory()->student()->create(['avatar_url' => $oldUrl]);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')
            ->once()
            ->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->actingAs($user)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('new.png'),
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHasErrors([
                'avatar' => '画像をアップロードできませんでした。もう一度お試しください。',
            ]);

        $this->assertSame($oldUrl, $user->fresh()->avatar_url);
    }

    public function test_avatar_delete_failure_keeps_existing_profile_image(): void
    {
        $oldUrl = 'http://localhost/storage/avatars/old.png';
        $user = User::factory()->student()->create(['avatar_url' => $oldUrl]);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('url')
            ->once()
            ->with('avatars')
            ->andReturn('http://localhost/storage/avatars');
        $disk->shouldReceive('delete')
            ->once()
            ->with('avatars/old.png')
            ->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->actingAs($user)
            ->delete(route('settings.avatar.destroy'))
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHasErrors([
                'avatar' => 'アイコン画像を削除できませんでした。もう一度お試しください。',
            ]);

        $this->assertSame($oldUrl, $user->fresh()->avatar_url);
    }

    public function test_old_avatar_cleanup_failure_is_logged_without_failing_replacement(): void
    {
        $oldUrl = 'http://localhost/storage/avatars/old.png';
        $user = User::factory()->student()->create(['avatar_url' => $oldUrl]);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn('avatars/new.png');
        $disk->shouldReceive('url')
            ->once()
            ->withArgs(fn (string $path): bool => str_starts_with($path, 'avatars/'))
            ->andReturnUsing(fn (string $path): string => 'http://localhost/storage/'.$path);
        $disk->shouldReceive('url')
            ->once()
            ->with('avatars')
            ->andReturn('http://localhost/storage/avatars');
        $disk->shouldReceive('delete')
            ->once()
            ->with('avatars/old.png')
            ->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        Log::spy();

        $this->actingAs($user)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('new.png'),
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHas('success', 'アバター画像を更新しました。');

        $this->assertStringStartsWith(
            'http://localhost/storage/avatars/',
            $user->fresh()->avatar_url,
        );
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_avatar_delete_succeeds_when_avatar_is_not_set(): void
    {
        $user = User::factory()->student()->create();

        $this->actingAs($user)
            ->delete(route('settings.avatar.destroy'))
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHas('success', 'アバター画像を削除しました。');

        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_avatar_delete_removes_saved_file_and_clears_profile_image(): void
    {
        Storage::fake('public');
        $path = 'avatars/current.png';
        Storage::disk('public')->put($path, 'avatar');
        $user = User::factory()->student()->create([
            'avatar_url' => Storage::disk('public')->url($path),
        ]);

        $this->actingAs($user)
            ->delete(route('settings.avatar.destroy'))
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertSessionHas('success', 'アバター画像を削除しました。');

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_password_update_keeps_current_session_and_hashes_new_password(): void
    {
        $user = User::factory()->student()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)
            ->put(route('settings.password.update'), [
                'current_password' => 'current-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'password']))
            ->assertSessionHas('success', 'パスワードを変更しました。');

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertNotSame('new-password', $user->fresh()->password);
    }

    public function test_two_login_sessions_keep_only_the_origin_session_after_password_change(): void
    {
        $user = User::factory()->student()->create([
            'password' => Hash::make('original-password'),
        ]);
        $cookieName = config('session.cookie');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'original-password',
        ])->assertRedirect();
        $otherSessionId = $this->app['session']->getId();
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->withCookie($cookieName, $otherSessionId);
        $this->get(route('settings.profile.edit'))->assertOk();

        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->withCookie($cookieName, str_repeat('a', 40));
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'original-password',
        ])->assertRedirect();
        $originSessionId = $this->app['session']->getId();
        $this->assertNotSame($otherSessionId, $originSessionId);

        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->withCookie($cookieName, $originSessionId);
        $this->put(route('settings.password.update'), [
            'current_password' => 'original-password',
            'password' => 'updated-password',
            'password_confirmation' => 'updated-password',
        ])->assertRedirect(route('settings.profile.edit', ['tab' => 'password']));

        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->withCookie($cookieName, $originSessionId);
        $this->get(route('settings.profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($user);

        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->withCookie($cookieName, $otherSessionId);
        $this->get(route('dashboard.index'))->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_validation_reports_field_specific_messages(): void
    {
        $user = User::factory()->admin()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)
            ->put(route('settings.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'password']))
            ->assertSessionHasErrors([
                'current_password' => '現在のパスワードが正しくありません。',
                'password' => '新しいパスワードは8文字以上で入力してください。',
                'password_confirmation' => '新しいパスワードが一致しません。',
            ]);

        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }

    public function test_non_string_current_password_shows_validation_error_instead_of_server_error(): void
    {
        $user = User::factory()->student()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)
            ->put(route('settings.password.update'), [
                'current_password' => ['invalid'],
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'password']))
            ->assertSessionHasErrors([
                'current_password' => '現在のパスワードは文字列で入力してください。',
            ]);

        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }

    public function test_password_errors_render_password_tab_and_never_repopulate_passwords(): void
    {
        $user = User::factory()->student()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)
            ->put(route('settings.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'not-matching',
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'password']));

        $this->get(route('settings.profile.edit', ['tab' => 'password']))
            ->assertOk()
            ->assertSee('aria-selected="true"', false)
            ->assertSee('name="current_password"', false)
            ->assertDontSee('name="name"', false)
            ->assertSee('現在のパスワードが正しくありません。')
            ->assertSee('新しいパスワードが一致しません。')
            ->assertDontSee('value="wrong-password"', false)
            ->assertDontSee('value="new-password"', false)
            ->assertDontSee('value="not-matching"', false);
    }

    public function test_password_required_fields_show_specified_messages(): void
    {
        $user = User::factory()->student()->create();

        $this->actingAs($user)
            ->put(route('settings.password.update'), [
                'current_password' => '',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'password']))
            ->assertSessionHasErrors([
                'current_password' => '現在のパスワードを入力してください。',
                'password' => '新しいパスワードを入力してください。',
                'password_confirmation' => '新しいパスワード（確認）を入力してください。',
            ]);
    }

    public function test_password_change_is_available_to_all_roles(): void
    {
        $users = [
            User::factory()->student()->graduated()->create(),
            User::factory()->coach()->create(),
            User::factory()->admin()->create(),
        ];

        foreach ($users as $user) {
            Auth::logout();
            $this->app['session']->flush();

            $this->actingAs($user)
                ->put(route('settings.password.update'), [
                    'current_password' => 'password',
                    'password' => 'updated-password',
                    'password_confirmation' => 'updated-password',
                ])
                ->assertRedirect(route('settings.profile.edit', ['tab' => 'password']));

            $this->assertTrue(Hash::check('updated-password', $user->fresh()->password));
        }
    }

    public function test_other_web_routes_reject_sessions_with_an_outdated_password_hash(): void
    {
        $user = User::factory()->student()->create([
            'password' => Hash::make('new-password'),
        ]);

        $this->actingAs($user)
            ->withSession(['password_hash_web' => Hash::make('old-password')])
            ->get(route('dashboard.index'))
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_settings_routes_reject_unauthenticated_requests(): void
    {
        $this->patch(route('settings.profile.update'), ['name' => '更新'])
            ->assertRedirect('/login');
        $this->assertGuest();

        $this->post(route('settings.avatar.store'))
            ->assertRedirect('/login');
        $this->assertGuest();

        $this->delete(route('settings.avatar.destroy'))
            ->assertRedirect('/login');
        $this->assertGuest();

        $this->put(route('settings.password.update'))
            ->assertRedirect('/login');
        $this->assertGuest();
    }

    private function avatarPathFromUrl(string $url): string
    {
        $baseUrl = rtrim(Storage::disk('public')->url('avatars'), '/').'/';

        return 'avatars/'.substr($url, strlen($baseUrl));
    }
}
