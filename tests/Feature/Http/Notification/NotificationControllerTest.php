<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\Meeting;
use App\Models\User;
use App\Notifications\BusinessEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_only_the_authenticated_users_notifications_and_unread_count(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $unread = $this->createNotification($user);
        $this->createNotification($user, readAt: now()->subDay());
        $this->createNotification($other);

        $response = $this->actingAs($user)->get(route('notifications.index'));

        $response->assertOk()
            ->assertViewHas('notifications', fn ($notifications) => $notifications->total() === 2
                && $notifications->contains('id', $unread->id))
            ->assertViewHas('unreadCount', 1)
            ->assertViewHas('tab', 'all');
    }

    public function test_unread_tab_filters_notifications_and_is_preserved_in_pagination(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        foreach (range(1, 21) as $_) {
            $this->createNotification($user);
        }
        $this->createNotification($user, readAt: now());

        $response = $this->actingAs($user)->get(route('notifications.index', ['tab' => 'unread']));

        $response->assertOk()
            ->assertViewHas('notifications', fn ($notifications) => $notifications->total() === 21
                && $notifications->count() === 20
                && $notifications->url(2) !== null
                && str_contains($notifications->url(2), 'tab=unread'))
            ->assertViewHas('unreadCount', 21);
    }

    public function test_invalid_tab_is_redirected_to_all_notifications_with_warning(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $this->actingAs($user)
            ->get(route('notifications.index', ['tab' => ['unread']]))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('warning', '通知の表示条件が不正なため、全件を表示しています。');
    }

    public function test_inactive_users_are_forbidden_before_invalid_tab_or_notification_lookup(): void
    {
        foreach (['invited', 'withdrawn'] as $status) {
            foreach (['student', 'coach'] as $role) {
                $user = User::factory()->create(['role' => $role, 'status' => $status]);

                $this->actingAs($user)
                    ->get(route('notifications.index', ['tab' => ['invalid']]))
                    ->assertForbidden();
                $this->post(route('notifications.markAsRead', 'missing-id'))
                    ->assertForbidden();
                $this->post(route('notifications.markAllAsRead'))
                    ->assertForbidden();
            }
        }
    }

    public function test_admin_and_graduated_users_can_open_their_notification_list(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('notifications.index'))
            ->assertOk();

        $this->actingAs(User::factory()->coach()->graduated()->create())
            ->get(route('notifications.index'))
            ->assertOk();
    }

    public function test_notification_read_is_owner_scoped_and_missing_or_foreign_notifications_return_404(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $foreignNotification = $this->createNotification($other);
        $missingId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('notifications.markAsRead', $foreignNotification->id))
            ->assertNotFound();
        $this->post(route('notifications.markAsRead', $missingId))->assertNotFound();

        $this->assertNull($foreignNotification->fresh()->read_at);
    }

    public function test_read_notification_redirects_to_meeting_and_preserves_first_read_at(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();
        $firstReadAt = now()->subDays(2)->startOfSecond();
        $notification = $this->createNotification($student, [
            'target_type' => 'meeting',
            'target_id' => $meeting->id,
        ], $firstReadAt);

        $this->actingAs($student)
            ->post(route('notifications.markAsRead', $notification->id))
            ->assertRedirect(route('meetings.show', $meeting));

        $this->assertTrue($notification->fresh()->read_at->equalTo($firstReadAt));
    }

    public function test_deleted_or_inaccessible_target_is_read_and_redirects_to_notification_index(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $notification = $this->createNotification($user, [
            'target_type' => 'meeting',
            'target_id' => (string) Str::ulid(),
        ]);

        $this->actingAs($user)
            ->post(route('notifications.markAsRead', $notification->id))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('warning', '通知の対象ページを表示できないため、通知一覧に戻りました。');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_as_read_only_updates_unread_notifications_for_the_current_user(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $ownUnread = $this->createNotification($user);
        $ownReadAt = now()->subDay()->startOfSecond();
        $ownRead = $this->createNotification($user, readAt: $ownReadAt);
        $otherUnread = $this->createNotification($other);

        $this->actingAs($user)
            ->post(route('notifications.markAllAsRead'))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('success', 'すべての通知を既読にしました。');

        $this->assertNotNull($ownUnread->fresh()->read_at);
        $this->assertTrue($ownRead->fresh()->read_at->equalTo($ownReadAt));
        $this->assertNull($otherUnread->fresh()->read_at);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createNotification(
        User $user,
        array $data = [],
        mixed $readAt = null,
    ): DatabaseNotification {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => BusinessEventNotification::class,
            'data' => array_merge([
                'notification_type' => 'meeting_reserved',
                'title' => 'テスト通知',
                'message' => 'テスト本文',
                'body' => 'テスト本文',
                'target_type' => 'meeting',
                'target_id' => (string) Str::ulid(),
            ], $data),
            'read_at' => $readAt,
        ]);
    }
}
