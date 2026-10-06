<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\Dashboard\FetchCoachDashboardAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_only_open_unanswered_assigned_published_questions_and_orders_recent_five(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $assignment = CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id, 'user_id' => $coach->id,
        ]);
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = QaThread::factory()->for($certification)->create(['created_at' => now()->addSeconds($i)])->id;
        }
        QaThread::factory()->resolved()->for($certification)->create();
        $answered = QaThread::factory()->for($certification)->create();
        QaReply::factory()->for($answered, 'thread')->create();
        QaThread::factory()->create();
        foreach (['draft', 'archived'] as $status) {
            $hidden = Certification::factory()->create(['status' => $status]);
            CertificationCoachAssignment::factory()->create(['certification_id' => $hidden->id, 'user_id' => $coach->id]);
            QaThread::factory()->for($hidden)->create();
        }

        $action = app(FetchCoachDashboardAction::class);
        $summary = $action($coach);

        $this->assertSame(6, $summary->unansweredQaCount);
        $this->assertSame(array_slice(array_reverse($ids), 0, 5), $summary->recentQaThreads->pluck('id')->all());
        $assignment->update(['unassigned_at' => now()]);
        $this->assertSame(0, $action($coach)->unansweredQaCount);
        $assignment->update(['unassigned_at' => null]);
        $this->assertSame(6, $action($coach)->unansweredQaCount);
        $certification->update(['status' => 'archived']);
        $this->assertSame(0, $action($coach)->unansweredQaCount);
        $this->assertCount(0, $action($coach)->recentQaThreads);
    }

    public function test_dashboard_renders_withdrawn_author_without_blade_changes(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $thread->certification_id, 'user_id' => $coach->id,
        ]);
        $thread->user->update(['status' => 'withdrawn', 'name' => 'DoNotDisplay']);

        $this->actingAs($coach)->get(route('dashboard.index'))
            ->assertOk()->assertSee('不明')->assertDontSee('DoNotDisplay')->assertSee($thread->title);
    }
}
