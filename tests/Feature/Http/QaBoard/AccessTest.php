<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_before_resource_resolution(): void
    {
        $thread = QaThread::factory()->create();

        foreach ([$thread->id, (string) Str::ulid()] as $id) {
            $this->get('/qa-board/'.$id)->assertRedirect(route('login'));
            $this->delete('/admin/qa-board/'.$id)->assertRedirect(route('login'));
        }
    }

    public function test_wrong_roles_are_forbidden_even_for_missing_resources(): void
    {
        $thread = QaThread::factory()->create();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        foreach ([$thread->id, (string) Str::ulid()] as $id) {
            $this->actingAs($admin)->get('/qa-board/'.$id)->assertForbidden();
            foreach ([$student, $coach] as $viewer) {
                $this->actingAs($viewer)->delete('/admin/qa-board/'.$id)->assertForbidden();
            }
        }
    }

    public function test_inactive_students_and_coaches_are_forbidden_before_binding(): void
    {
        foreach (['student', 'coach'] as $role) {
            foreach (['invited', 'graduated', 'withdrawn'] as $status) {
                $viewer = User::factory()->create(['role' => $role, 'status' => $status]);
                $this->actingAs($viewer)->get('/qa-board/'.Str::ulid())->assertForbidden();
                $this->get('/qa-board')->assertForbidden();
            }
        }
    }

    public function test_hidden_questions_return_404_for_every_operation(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->draft()->create();
        $thread = QaThread::factory()->for($student, 'user')->for($certification)->create();
        $reply = QaReply::factory()->for($student, 'user')->for($thread, 'thread')->create();

        $this->actingAs($student);
        foreach (['draft', 'archived'] as $status) {
            $certification->update(['status' => $status]);
            $this->get(route('qa-board.show', $thread))->assertNotFound();
            $this->get(route('qa-board.edit', $thread))->assertNotFound();
            $this->patch(route('qa-board.update', $thread), ['title' => 'x', 'body' => 'x'])->assertNotFound();
            $this->delete(route('qa-board.destroy', $thread))->assertNotFound();
            $this->post(route('qa-board.resolve', $thread))->assertNotFound();
            $this->post(route('qa-board.unresolve', $thread))->assertNotFound();
            $this->post(route('qa-board.replies.store', $thread), ['body' => 'x'])->assertNotFound();
            $this->get(route('qa-board.replies.edit', [$thread, $reply]))->assertNotFound();
            $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => 'x'])->assertNotFound();
            $this->delete(route('qa-board.replies.destroy', [$thread, $reply]))->assertNotFound();
        }
    }

    public function test_visible_posts_owned_by_others_cannot_be_changed(): void
    {
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->for($thread, 'thread')->create();
        $viewer = User::factory()->student()->create();

        $this->actingAs($viewer)->get(route('qa-board.show', $thread))->assertOk();
        $this->get(route('qa-board.edit', $thread))->assertForbidden();
        $this->patch(route('qa-board.update', $thread), ['title' => 'x', 'body' => 'x'])->assertForbidden();
        $this->delete(route('qa-board.destroy', $thread))->assertForbidden();
        $this->post(route('qa-board.resolve', $thread))->assertForbidden();
        $this->post(route('qa-board.unresolve', $thread))->assertForbidden();
        $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => 'x'])->assertForbidden();
        $this->delete(route('qa-board.replies.destroy', [$thread, $reply]))->assertForbidden();
    }

    public function test_coach_access_tracks_current_assignment_and_publication(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->for($coach, 'user')->for($thread, 'thread')->create();
        $assignment = CertificationCoachAssignment::factory()->create([
            'certification_id' => $thread->certification_id,
            'user_id' => $coach->id,
        ]);

        $this->actingAs($coach)->get(route('qa-board.show', $thread))->assertOk();
        $this->get(route('qa-board.create'))->assertForbidden();
        $this->post(route('qa-board.store'), [])->assertForbidden();
        $this->get(route('qa-board.edit', $thread))->assertForbidden();
        $this->post(route('qa-board.resolve', $thread))->assertForbidden();
        $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => 'changed'])->assertRedirect();

        $assignment->update(['unassigned_at' => now()]);
        $this->get(route('qa-board.show', $thread))->assertNotFound();
        $this->delete(route('qa-board.replies.destroy', [$thread, $reply]))->assertNotFound();
        $assignment->update(['unassigned_at' => null]);
        $this->get(route('qa-board.replies.edit', [$thread, $reply]))->assertOk();
        $thread->certification->update(['status' => 'archived']);
        $this->get(route('qa-board.show', $thread))->assertNotFound();
    }

    public function test_mismatched_nested_reply_returns_404_including_admin_deletion(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->for($student, 'user')->create();

        $this->actingAs($student)->get(route('qa-board.replies.edit', [$thread, $reply]))->assertNotFound();
        $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => 'x'])->assertNotFound();
        $this->delete(route('qa-board.replies.destroy', [$thread, $reply]))->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.qa-board.replies.destroy', [$thread, $reply]))->assertNotFound();
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    public function test_allowed_viewers_receive_404_for_nonexistent_questions_and_replies(): void
    {
        $thread = QaThread::factory()->create();
        $missing = (string) Str::ulid();

        $this->actingAs(User::factory()->student()->create())->get('/qa-board/'.$missing)->assertNotFound();
        $this->get(route('qa-board.replies.edit', [$thread, $missing]))->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/qa-board/'.$missing)->assertNotFound();
    }
}
