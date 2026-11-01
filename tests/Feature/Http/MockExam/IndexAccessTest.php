<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Tests\TestCase;

class IndexAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_from_mock_exam_index(): void
    {
        $this->get(route('admin.mock-exams.index'))
            ->assertRedirect(route('login'));
    }

    public function test_coach_sees_only_mock_exams_of_assigned_certifications(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $assigned = Certification::factory()->published()->create();
        $unassigned = Certification::factory()->published()->create();

        $assigned->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $assignedExam = MockExam::factory()->forCertification($assigned)->create(['title' => '担当資格の模試']);
        $unassignedExam = MockExam::factory()->forCertification($unassigned)->create(['title' => '担当外資格の模試']);

        $response = $this->actingAs($coach)->get(route('admin.mock-exams.index'));

        $response->assertOk();
        /** @var LengthAwarePaginator $mockExams */
        $mockExams = $response->viewData('mockExams');
        $ids = $mockExams->getCollection()->pluck('id')->all();

        $this->assertContains($assignedExam->id, $ids);
        $this->assertNotContains($unassignedExam->id, $ids);
        $response->assertSee('担当資格の模試');
        $response->assertDontSee('担当外資格の模試');
    }

    public function test_coach_with_no_assignment_sees_no_mock_exams(): void
    {
        $coach = User::factory()->coach()->create();
        MockExam::factory()->create(['title' => '他コーチの模試']);

        $response = $this->actingAs($coach)->get(route('admin.mock-exams.index'));

        $response->assertOk();
        /** @var LengthAwarePaginator $mockExams */
        $mockExams = $response->viewData('mockExams');
        $this->assertCount(0, $mockExams);
        $response->assertDontSee('他コーチの模試');
    }
}
