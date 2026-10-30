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

class ApiNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_own_notifications_with_resource_shape_and_unread_count(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $unread = $this->createNotification($user);
        $this->createNotification($user, readAt: now()->subDay());
        $this->createNotification($other);

        $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonStructure([
                'data' => [[
                    'id', 'title', 'message', 'read', 'created_at', 'created_at_human', 'url',
                ]],
                'unread_count',
            ])
            ->assertJsonFragment(['id' => $unread->id, 'read' => false]);
    }

    public function test_unread_tab_returns_only_unread_notifications_but_full_unread_count(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $this->createNotification($user);
        $this->createNotification($user);
        $this->createNotification($user, readAt: now());

        $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.notifications.index', ['tab' => 'unread']))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('unread_count', 2);
    }

    public function test_index_limits_results_to_twenty_items(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        foreach (range(1, 21) as $_) {
            $this->createNotification($user);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('unread_count', 21);
    }

    public function test_invalid_tab_returns_422_json(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.notifications.index', ['tab' => 'invalid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tab']);
    }

    public function test_empty_tab_returns_422_and_omitted_tab_defaults_to_all(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $this->createNotification($user);

        $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.notifications.index', ['tab' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tab']);

        $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_guests_receive_401_json(): void
    {
        $this->getJson(route('api.v1.notifications.index'))->assertUnauthorized();
        $this->postJson(route('api.v1.notifications.read', 'missing-id'))->assertUnauthorized();
        $this->postJson(route('api.v1.notifications.readAll'))->assertUnauthorized();
    }

    public function test_inactive_users_receive_403_json(): void
    {
        foreach (['invited', 'withdrawn'] as $status) {
            $user = User::factory()->create(['role' => 'student', 'status' => $status]);

            $this->actingAs($user, 'sanctum')
                ->getJson(route('api.v1.notifications.index'))
                ->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->postJson(route('api.v1.notifications.readAll'))
                ->assertForbidden();
        }
    }

    public function test_read_marks_notification_and_returns_unread_count_without_redirect(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $notification = $this->createNotification($user);
        $this->createNotification($user);
        $firstReadAt = now()->subDays(2)->startOfSecond();
        $alreadyRead = $this->createNotification($user, readAt: $firstReadAt);

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.notifications.read', $notification->id))
            ->assertOk()
            ->assertExactJson(['unread_count' => 1]);

        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.notifications.read', $alreadyRead->id))
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->assertTrue($alreadyRead->fresh()->read_at->equalTo($firstReadAt));
    }

    public function test_read_returns_404_for_missing_or_foreign_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $foreign = $this->createNotification($other);

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.notifications.read', 'missing-id'))
            ->assertNotFound();
        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.notifications.read', $foreign->id))
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_read_all_marks_only_own_unread_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $this->createNotification($user);
        $this->createNotification($user);
        $otherUnread = $this->createNotification($other);

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.notifications.readAll'))
            ->assertOk()
            ->assertExactJson(['unread_count' => 0]);

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertNull($otherUnread->fresh()->read_at);
    }

    public function test_url_points_to_meeting_for_meeting_notifications(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();
        $this->createNotification($student, [
            'target_type' => 'meeting',
            'target_id' => $meeting->id,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonPath('data.0.url', route('meetings.show', $meeting));
    }

    public function test_url_points_to_notification_detail_for_admin_announcements(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $notification = $this->createNotification($user, [
            'notification_type' => 'admin_announcement',
            'target_type' => null,
            'target_id' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonPath('data.0.url', route('notifications.show', $notification->id));
    }

    public function test_url_falls_back_to_notification_list_when_target_is_inaccessible(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $this->createNotification($user, [
            'target_type' => 'meeting',
            'target_id' => (string) Str::ulid(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonPath('data.0.url', route('notifications.index'));
    }

    public function test_unread_tab_returns_old_unread_beyond_latest_twenty_items(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $oldUnread = $this->createNotification($user);
        $oldUnread->forceFill(['created_at' => now()->subDays(2)])->save();

        foreach (range(1, 20) as $_) {
            $this->createNotification($user, readAt: now());
        }

        $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.notifications.index', ['tab' => 'unread']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $oldUnread->id)
            ->assertJsonPath('unread_count', 1);

        $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonMissing(['id' => $oldUnread->id]);
    }

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
