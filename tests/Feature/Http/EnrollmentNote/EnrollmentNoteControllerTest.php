<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentNoteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_note_and_author_is_recorded(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = $this->createEnrollment();

        $this->actingAs($admin)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '管理者メモ'])
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', 'メモを追加しました。');

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $admin->id,
            'body' => '管理者メモ',
        ]);
    }

    public function test_assigned_coach_can_add_note(): void
    {
        $enrollment = $this->createEnrollment();
        $coach = $this->assignCoach($enrollment);

        $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '担当メモ'])
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', 'メモを追加しました。');

        $this->assertDatabaseHas('enrollment_notes', ['author_id' => $coach->id, 'body' => '担当メモ']);
    }

    public function test_unassigned_coach_student_and_guest_cannot_add_note(): void
    {
        $enrollment = $this->createEnrollment();
        $otherCoach = User::factory()->coach()->create();
        $student = $enrollment->user;

        $this->actingAs($otherCoach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '担当外'])
            ->assertForbidden();
        $this->actingAs($student)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '受講生'])
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->post(route('enrollments.notes.store', $enrollment), ['body' => '未ログイン'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    public function test_note_body_is_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = $this->createEnrollment();

        $this->actingAs($admin)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '   '])
            ->assertSessionHasErrors('body');
        $this->actingAs($admin)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => str_repeat('あ', 2001)])
            ->assertSessionHasErrors('body');
        $this->actingAs($admin)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => str_repeat('あ', 2000)])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('enrollment_notes', 1);
    }

    public function test_author_coach_can_edit_and_update_own_note(): void
    {
        $enrollment = $this->createEnrollment();
        $coach = $this->assignCoach($enrollment);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create(['body' => '元']);

        $this->actingAs($coach)->get(route('enrollment-notes.edit', $note))->assertOk();
        $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => '更新後'])
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', 'メモを更新しました。');

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '更新後']);
    }

    public function test_coach_cannot_edit_update_or_delete_other_authors_note(): void
    {
        $enrollment = $this->createEnrollment();
        $coach = $this->assignCoach($enrollment);
        $otherCoach = $this->assignCoach($enrollment);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($otherCoach)->create(['body' => '他者']);

        $this->actingAs($coach)->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => '改ざん'])
            ->assertForbidden();
        $this->actingAs($coach)
            ->delete(route('enrollment-notes.destroy', $note))
            ->assertForbidden();

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '他者']);
    }

    public function test_admin_can_edit_update_and_delete_any_note(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = $this->createEnrollment();
        $coach = $this->assignCoach($enrollment);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create(['body' => '元']);

        $this->actingAs($admin)->get(route('enrollment-notes.edit', $note))->assertOk();
        $this->actingAs($admin)
            ->patch(route('enrollment-notes.update', $note), ['body' => '管理者更新'])
            ->assertSessionHas('success', 'メモを更新しました。');
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '管理者更新']);

        $this->actingAs($admin)
            ->delete(route('enrollment-notes.destroy', $note))
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', 'メモを削除しました。');
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public function test_author_coach_can_delete_own_note_permanently(): void
    {
        $enrollment = $this->createEnrollment();
        $coach = $this->assignCoach($enrollment);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create();

        $this->actingAs($coach)
            ->delete(route('enrollment-notes.destroy', $note))
            ->assertSessionHas('success', 'メモを削除しました。');

        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public function test_notes_are_ordered_by_created_at_desc_then_id_desc(): void
    {
        $enrollment = $this->createEnrollment();
        $coach = $this->assignCoach($enrollment);
        $sameTime = now()->subDay();
        $older = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)
            ->create(['created_at' => $sameTime->copy()->subHour()]);
        $lowerId = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)
            ->create(['created_at' => $sameTime]);
        $higherId = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)
            ->create(['created_at' => $sameTime]);
        $expected = collect([$lowerId->id, $higherId->id])->sortDesc()->values()->all();

        $ids = $enrollment->notes()->orderByDesc('created_at')->pluck('id')->all();

        $this->assertSame([...$expected, $older->id], $ids);
    }

    public function test_detail_page_shows_notes_to_coach_and_admin_but_not_to_student(): void
    {
        $enrollment = $this->createEnrollment();
        $coach = $this->assignCoach($enrollment);
        $admin = User::factory()->admin()->create();
        EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create(['body' => 'メモ本文ABC']);

        $this->actingAs($coach)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertSee('メモ本文ABC');
        $this->actingAs($admin)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertSee('メモ本文ABC');
        $this->actingAs($enrollment->user)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertDontSee('メモ本文ABC');
    }

    public function test_notes_of_deleted_enrollment_are_hidden_and_cannot_be_operated(): void
    {
        $enrollment = $this->createEnrollment();
        $coach = $this->assignCoach($enrollment);
        $admin = User::factory()->admin()->create();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create(['body' => '削除対象メモ']);

        $enrollment->delete();

        $this->actingAs($coach)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertDontSee('削除対象メモ');
        $this->actingAs($admin)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '追加不可'])
            ->assertForbidden();
        $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => '更新不可'])
            ->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('enrollment-notes.destroy', $note))
            ->assertForbidden();

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '削除対象メモ']);
    }

    public function test_notes_remain_operable_after_enrollment_is_passed(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->passed()->create();
        $coach = $this->assignCoach($enrollment);

        $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '修了後メモ'])
            ->assertSessionHas('success', 'メモを追加しました。');
    }

    public function test_former_assigned_coach_cannot_edit_update_or_delete_own_note(): void
    {
        $enrollment = $this->createEnrollment();
        $coach = User::factory()->coach()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $enrollment->certification_id,
            'user_id' => $coach->id,
            'unassigned_at' => now(),
        ]);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create(['body' => '元']);

        $this->actingAs($coach)->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => '更新後'])
            ->assertForbidden()
            ->assertSessionMissing('success');
        $this->actingAs($coach)
            ->delete(route('enrollment-notes.destroy', $note))
            ->assertForbidden()
            ->assertSessionMissing('success');

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '元']);
    }

    public function test_student_and_guest_cannot_edit_update_or_delete_note(): void
    {
        $enrollment = $this->createEnrollment();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($this->assignCoach($enrollment))->create(['body' => '元']);

        $this->actingAs($enrollment->user)->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->actingAs($enrollment->user)
            ->patch(route('enrollment-notes.update', $note), ['body' => '更新後'])
            ->assertForbidden();
        $this->actingAs($enrollment->user)
            ->delete(route('enrollment-notes.destroy', $note))
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->get(route('enrollment-notes.edit', $note))->assertRedirect(route('login'));
        $this->patch(route('enrollment-notes.update', $note), ['body' => '更新後'])->assertRedirect(route('login'));
        $this->delete(route('enrollment-notes.destroy', $note))->assertRedirect(route('login'));

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '元']);
    }

    private function createEnrollment(): Enrollment
    {
        $student = User::factory()->student()->inProgress()->create();

        return Enrollment::factory()->for($student)->learning()->create();
    }

    private function assignCoach(Enrollment $enrollment): User
    {
        $coach = User::factory()->coach()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $enrollment->certification_id,
            'user_id' => $coach->id,
        ]);

        return $coach;
    }
}
