<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentGoalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_student_can_create_duplicate_goals_and_view_them_in_required_order(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $sameDayOlder = $this->createGoal($enrollment, [
            'title' => '同一期日の古い目標',
            'target_date' => '2026-10-20',
        ]);
        $sameDayNewer = $this->createGoal($enrollment, [
            'title' => '同一期日の新しい目標',
            'target_date' => '2026-10-20',
        ]);
        $sameDayOlder->forceFill(['created_at' => '2026-10-01 10:00:00'])->save();
        $sameDayNewer->forceFill(['created_at' => '2026-10-02 10:00:00'])->save();
        $earliest = $this->createGoal($enrollment, [
            'title' => '最も近い期日',
            'target_date' => '2026-10-15',
        ]);
        $noDate = $this->createGoal($enrollment, [
            'title' => '期日なし',
            'target_date' => null,
        ]);
        $achieved = $this->createGoal($enrollment, [
            'title' => '達成済み',
            'target_date' => '2026-10-10',
            'achieved_at' => now(),
        ]);

        $createData = [
            'title' => '同じ内容を複数登録',
            'description' => '詳細',
            'target_date' => '2026-10-12',
        ];
        $this->actingAs($student)
            ->post(route('enrollments.goals.store', $enrollment), $createData)
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', '目標を追加しました。');
        $this->actingAs($student)
            ->post(route('enrollments.goals.store', $enrollment), $createData)
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', '目標を追加しました。');

        $this->assertDatabaseCount('enrollment_goals', 7);
        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => $createData['title'],
            'achieved_at' => null,
        ]);

        $response = $this->get(route('enrollments.show', $enrollment));
        $response->assertOk();
        $response->assertSeeInOrder([
            '同じ内容を複数登録',
            '同じ内容を複数登録',
            $earliest->title,
            $sameDayNewer->title,
            $sameDayOlder->title,
            $noDate->title,
            $achieved->title,
        ]);
    }

    public function test_validation_rejects_invalid_input_and_accepts_maximum_lengths(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $url = route('enrollments.goals.store', $enrollment);

        $this->actingAs($student)
            ->from(route('enrollments.show', $enrollment))
            ->post($url, ['title' => '   '])
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHasErrors([
                'title' => '目標を入力してください。',
            ]);

        $this->from(route('enrollments.show', $enrollment))
            ->post($url, ['title' => str_repeat('a', 101)])
            ->assertSessionHasErrors([
                'title' => '目標は100文字以内で入力してください。',
            ])
            ->assertSessionHas('_old_input.title', str_repeat('a', 101));

        $this->from(route('enrollments.show', $enrollment))
            ->post($url, [
                'title' => '有効な目標',
                'description' => str_repeat('a', 1001),
            ])
            ->assertSessionHasErrors([
                'description' => '詳細は1000文字以内で入力してください。',
            ]);

        $this->from(route('enrollments.show', $enrollment))
            ->post($url, [
                'title' => '無効な日付',
                'target_date' => '2026-02-30',
            ])
            ->assertSessionHasErrors([
                'target_date' => '目標期日を正しく入力してください。',
            ]);

        $this->from(route('enrollments.show', $enrollment))
            ->post($url, ['title' => ['文字列ではない値']])
            ->assertSessionHasErrors([
                'title' => '目標は文字列で入力してください。',
            ]);

        $this->assertDatabaseCount('enrollment_goals', 0);

        $this->post($url, [
            'title' => str_repeat('a', 100),
            'description' => str_repeat('b', 1000),
            'target_date' => '2000-01-01',
        ])
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('enrollment_goals', 1);
    }

    public function test_update_clears_optional_values_and_preserves_achievement(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $achievedAt = Carbon::parse('2026-10-01 12:30:00');
        $goal = $this->createGoal($enrollment, [
            'title' => '編集前',
            'description' => '以前の詳細',
            'target_date' => '2026-10-20',
            'achieved_at' => $achievedAt,
        ]);

        $this->actingAs($student)
            ->patch(route('enrollment-goals.update', $goal), [
                'title' => '編集後',
                'description' => '   ',
                'target_date' => '',
            ])
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', '目標を更新しました。');

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '編集後',
            'description' => null,
            'target_date' => null,
            'achieved_at' => $achievedAt->toDateTimeString(),
        ]);
    }

    public function test_achievement_operations_are_idempotent_and_reachieved_again_with_new_time(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = $this->createGoal($enrollment);
        $achieveUrl = route('enrollment-goals.markAchieved', $goal);
        $unachieveUrl = route('enrollment-goals.unmarkAchieved', $goal);

        Carbon::setTestNow('2026-10-10 09:00:00');
        $this->actingAs($student)
            ->post($achieveUrl)
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', '目標を達成済みにしました。');
        $firstAchievedAt = $goal->fresh()->achieved_at;

        Carbon::setTestNow('2026-10-11 09:00:00');
        $this->post($achieveUrl)
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', '目標を達成済みにしました。');
        $this->assertTrue($goal->fresh()->achieved_at->equalTo($firstAchievedAt));

        $this->delete($unachieveUrl)
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', '目標を未達成に戻しました。');
        $this->assertNull($goal->fresh()->achieved_at);

        $this->delete($unachieveUrl)
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', '目標を未達成に戻しました。');
        $this->assertNull($goal->fresh()->achieved_at);

        Carbon::setTestNow('2026-10-12 09:00:00');
        $this->post($achieveUrl);
        $this->assertTrue($goal->fresh()->achieved_at->equalTo(Carbon::getTestNow()));
    }

    public function test_goal_and_parent_enrollment_are_physically_deleted_on_removal(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = $this->createGoal($enrollment);

        $this->actingAs($student)
            ->delete(route('enrollment-goals.destroy', $goal))
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', '目標を削除しました。');

        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);

        $anotherGoal = $this->createGoal($enrollment, ['title' => '親解除で削除される目標']);
        $this->delete(route('enrollments.destroy', $enrollment))
            ->assertRedirect(route('enrollments.index'));

        $this->assertSoftDeleted('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $anotherGoal->id]);
    }

    public function test_goal_routes_apply_auth_owner_role_and_enrollment_state_restrictions(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = $this->createGoal($enrollment);

        $this->get(route('enrollment-goals.edit', $goal))->assertRedirect(route('login'));

        $otherStudent = User::factory()->student()->inProgress()->create();
        $this->actingAs($otherStudent)
            ->patch(route('enrollment-goals.update', $goal), ['title' => '不正な更新'])
            ->assertForbidden();
        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => $goal->title,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('enrollment-goals.markAchieved', $goal))
            ->assertForbidden();

        foreach ([
            User::factory()->student()->invited()->create(),
            User::factory()->student()->graduated()->create(),
            User::factory()->student()->withdrawn()->create(),
        ] as $inactiveStudent) {
            $this->actingAs($inactiveStudent)
                ->post('/enrollment-goals/' . Str::ulid() . '/achieve')
                ->assertForbidden();
        }

        $this->actingAs($student)
            ->post('/enrollments/' . Str::ulid() . '/goals', ['title' => '存在しない登録'])
            ->assertNotFound();
        $this->post('/enrollment-goals/' . Str::ulid() . '/achieve')
            ->assertNotFound();

        $failedEnrollment = Enrollment::factory()->for($student)->failed()->create();
        $failedGoal = $this->createGoal($failedEnrollment);
        $this->post(route('enrollment-goals.markAchieved', $failedGoal))
            ->assertForbidden();
    }

    public function test_coach_and_admin_can_view_goals_without_management_controls(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = $this->createGoal($enrollment, ['title' => '閲覧専用の目標']);
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $enrollment->certification_id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        foreach ([$coach, $admin] as $viewer) {
            $response = $this->actingAs($viewer)
                ->get(route('enrollments.show', $enrollment));

            $response->assertOk()
                ->assertSeeText($goal->title)
                ->assertDontSee(route('enrollment-goals.edit', $goal))
                ->assertDontSee(route('enrollment-goals.destroy', $goal))
                ->assertDontSee(route('enrollment-goals.markAchieved', $goal))
                ->assertDontSee(route('enrollment-goals.unmarkAchieved', $goal));
        }

        $this->actingAs($coach)
            ->delete(route('enrollment-goals.destroy', $goal))
            ->assertForbidden();
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_soft_deleted_parent_hides_goal_operation_as_not_found(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = $this->createGoal($enrollment);
        $enrollment->delete();

        $this->actingAs($student)
            ->patch(route('enrollment-goals.update', $goal), ['title' => '変更されない'])
            ->assertNotFound();

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => $goal->title,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createGoal(Enrollment $enrollment, array $attributes = []): EnrollmentGoal
    {
        return $enrollment->goals()->create(array_merge([
            'title' => '目標 ' . Str::ulid(),
            'description' => null,
            'target_date' => null,
            'achieved_at' => null,
        ], $attributes));
    }
}
