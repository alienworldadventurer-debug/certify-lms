<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AnnouncementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_students_dispatch_reaches_only_in_progress_students(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $invited = User::factory()->student()->invited()->create();
        $graduated = User::factory()->student()->graduated()->create();
        $withdrawn = User::factory()->student()->withdrawn()->create();
        $coach = User::factory()->coach()->create();

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload())
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHas('success', 'お知らせを配信しました。');

        $this->assertSame(1, Announcement::query()->sole()->dispatched_count);
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(0, $invited->notifications()->count());
        $this->assertSame(0, $graduated->notifications()->count());
        $this->assertSame(0, $withdrawn->notifications()->count());
        $this->assertSame(0, $coach->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_certification_dispatch_reaches_enrolled_students_regardless_of_enrollment_status(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $other = Certification::factory()->published()->create();
        $learning = User::factory()->student()->inProgress()->create();
        $passed = User::factory()->student()->inProgress()->create();
        $failed = User::factory()->student()->inProgress()->create();
        $notEnrolledInTarget = User::factory()->student()->inProgress()->create();
        Enrollment::factory()->learning()->create(['user_id' => $learning->id, 'certification_id' => $certification->id]);
        Enrollment::factory()->passed()->create(['user_id' => $passed->id, 'certification_id' => $certification->id]);
        Enrollment::factory()->failed()->create(['user_id' => $failed->id, 'certification_id' => $certification->id]);
        Enrollment::factory()->learning()->create(['user_id' => $notEnrolledInTarget->id, 'certification_id' => $other->id]);

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, $learning->notifications()->count());
        $this->assertSame(1, $passed->notifications()->count());
        $this->assertSame(1, $failed->notifications()->count());
        $this->assertSame(0, $notEnrolledInTarget->notifications()->count());
        $this->assertSame(3, Announcement::query()->sole()->dispatched_count);
    }

    public function test_user_dispatch_reaches_only_the_selected_student(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->student()->inProgress()->create();
        $another = User::factory()->student()->inProgress()->create();

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $target->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, $target->notifications()->count());
        $this->assertSame(0, $another->notifications()->count());
        $this->assertSame(1, Announcement::query()->sole()->dispatched_count);
    }

    public function test_zero_recipients_creates_history_without_notifications_or_error(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload())
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHas('success', 'お知らせを配信しました。');

        $this->assertSame(0, Announcement::query()->sole()->dispatched_count);
        Notification::assertNothingSent();
    }

    public function test_failure_during_dispatch_rolls_back_and_returns_input(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        Notification::shouldReceive('sendNow')
            ->once()
            ->andThrow(new RuntimeException('db down'));

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('error', 'お知らせの配信に失敗しました。')
            ->assertSessionHasInput('title', 'テスト配信');

        $this->assertSame(0, Announcement::query()->count());
        $this->assertSame(0, $student->notifications()->count());
    }

    public function test_store_validates_required_fields_and_target_consistency(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '   ',
            'body' => '',
            'target_type' => 'all_students',
            'target_certification_id' => Certification::factory()->published()->create()->id,
        ])->assertSessionHasErrors(['title', 'body', 'target_certification_id']);

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'target_type' => AnnouncementTargetType::Certification->value,
        ]))->assertSessionHasErrors(['target_certification_id']);

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => User::factory()->coach()->create()->id,
        ]))->assertSessionHasErrors(['target_user_id']);

        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_title_and_body_length_boundaries(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'title' => str_repeat('あ', 200),
            'body' => str_repeat('い', 5000),
        ]))->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'title' => str_repeat('あ', 201),
        ]))->assertSessionHasErrors(['title']);

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'body' => str_repeat('い', 5001),
        ]))->assertSessionHasErrors(['body']);
    }

    public function test_student_coach_and_guest_cannot_access_admin_announcement_screens(): void
    {
        $announcement = Announcement::factory()->create();

        $this->actingAs(User::factory()->student()->inProgress()->create())
            ->get(route('admin.announcements.index'))->assertForbidden();
        $this->actingAs(User::factory()->coach()->create())
            ->get(route('admin.announcements.create'))->assertForbidden();
        $this->actingAs(User::factory()->student()->inProgress()->create())
            ->post(route('admin.announcements.store'), $this->payload())->assertForbidden();
        $this->actingAs(User::factory()->coach()->create())
            ->get(route('admin.announcements.show', $announcement))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_admin_announcement_screens(): void
    {
        $announcement = Announcement::factory()->create();

        $this->get(route('admin.announcements.index'))->assertRedirect(route('login'));
        $this->get(route('admin.announcements.show', $announcement))->assertRedirect(route('login'));
    }

    public function test_edit_update_delete_routes_do_not_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $announcement = Announcement::factory()->create();

        $this->actingAs($admin)->get("/admin/announcements/{$announcement->id}/edit")->assertNotFound();
        $this->actingAs($admin)->put("/admin/announcements/{$announcement->id}")->assertStatus(405);
        $this->actingAs($admin)->delete("/admin/announcements/{$announcement->id}")->assertStatus(405);
    }

    public function test_index_lists_newest_first_and_show_displays_details(): void
    {
        $admin = User::factory()->admin()->create();
        $older = Announcement::factory()->create(['dispatched_at' => now()->subDay()]);
        $newer = Announcement::factory()->create(['dispatched_at' => now()]);

        $this->actingAs($admin)->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertViewHas('announcements', fn ($announcements) => $announcements->pluck('id')->all()
                === [$newer->id, $older->id]);

        $this->actingAs($admin)->get(route('admin.announcements.show', $newer))
            ->assertOk()
            ->assertSee($newer->title);
    }

    public function test_student_can_open_announcement_notification_without_marking_it_read(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'body' => "1行目\n2行目",
        ]))->assertSessionHasNoErrors();
        $notification = $student->notifications()->sole();

        $this->actingAs($student)->get(route('notifications.show', $notification->id))
            ->assertOk()
            ->assertSee('1行目', false);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_marking_admin_announcement_as_read_redirects_to_detail(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $announcement = Announcement::factory()->create();
        $notification = $student->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => AdminAnnouncementNotification::class,
            'data' => ['notification_type' => 'admin_announcement', 'title' => $announcement->title, 'announcement_id' => $announcement->id],
        ]);

        $this->actingAs($student)->post(route('notifications.markAsRead', $notification->id))
            ->assertRedirect(route('notifications.show', $notification->id));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_other_users_cannot_open_a_notification_detail(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $notification = $owner->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => AdminAnnouncementNotification::class,
            'data' => ['notification_type' => 'admin_announcement', 'title' => 'x'],
        ]);

        $this->actingAs($other)->get(route('notifications.show', $notification->id))->assertNotFound();
    }

    public function test_mail_subject_and_lines_escape_markdown(): void
    {
        $student = User::factory()->student()->inProgress()->create(['name' => '山田']);
        $announcement = Announcement::factory()->make([
            'title' => '重要',
            'body' => "# 見出し\n<b>太字</b>",
        ]);

        $mail = (new AdminAnnouncementNotification($announcement, (string) Str::uuid()))->toMail($student);

        $this->assertSame('【Certify LMS】重要', $mail->subject);
        $this->assertContains('\# 見出し', $mail->introLines);
        $this->assertContains('\<b\>太字\</b\>', $mail->introLines);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'テスト配信',
            'body' => '本文です。',
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => null,
            'target_user_id' => null,
        ], $overrides);
    }
}
