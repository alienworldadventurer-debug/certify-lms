<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShowQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_query_count_does_not_grow_with_replies_for_each_role(): void
    {
        foreach (['student', 'coach', 'admin'] as $role) {
            $viewer = User::factory()->create(['role' => $role]);
            $thread = QaThread::factory()->create();
            if ($role === 'coach') {
                CertificationCoachAssignment::factory()->create([
                    'certification_id' => $thread->certification_id,
                    'user_id' => $viewer->id,
                ]);
            }
            $ownReply = QaReply::factory()->for($thread, 'thread')->for($viewer, 'user')->create();
            $route = $role === 'admin' ? 'admin.qa-board.show' : 'qa-board.show';
            $this->actingAs($viewer);
            $this->get(route($route, $thread))->assertOk();

            $queryCounts = [];
            foreach ([1, 31] as $replyCount) {
                if ($replyCount === 31) {
                    QaReply::factory()->count(30)->for($thread, 'thread')->create();
                }
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $response = $this->get(route($route, $thread))->assertOk();
                    $queryCounts[] = count(DB::getQueryLog());
                } finally {
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }

                $loadedThread = $response->viewData('thread');
                $this->assertCount($replyCount, $loadedThread->replies);
                foreach ($loadedThread->replies as $reply) {
                    $this->assertTrue($reply->relationLoaded('thread'));
                    $this->assertSame($loadedThread, $reply->thread);
                }
                if ($role !== 'admin') {
                    $response->assertSee(route('qa-board.replies.edit', [$thread, $ownReply]));
                    foreach ($loadedThread->replies->where('user_id', '!=', $viewer->id) as $reply) {
                        $response->assertDontSee(route('qa-board.replies.edit', [$thread, $reply]));
                    }
                }
            }

            $this->assertSame($queryCounts[0], $queryCounts[1], 'Query count increased for '.$role);
        }
    }
}
