<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\QaThreadStatus;
use App\Models\QaReply;
use App\Models\QaThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class QaModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ulids_casts_and_bidirectional_relationships(): void
    {
        $thread = QaThread::factory()->resolved()->create();
        $reply = QaReply::factory()->for($thread, 'thread')->create();

        $this->assertTrue(Str::isUlid($thread->id));
        $this->assertTrue(Str::isUlid($reply->id));
        $this->assertSame(QaThreadStatus::Resolved, $thread->fresh()->status);
        $this->assertInstanceOf(Carbon::class, $thread->fresh()->resolved_at);
        $this->assertTrue($thread->certification->qaThreads->first()->is($thread));
        $this->assertTrue($thread->user->qaThreads->first()->is($thread));
        $this->assertTrue($thread->replies->first()->is($reply));
        $this->assertTrue($reply->thread->is($thread));
        $this->assertTrue($reply->user->qaReplies->first()->is($reply));
        $this->assertSame('未解決', QaThreadStatus::Open->label());
        $this->assertSame('解決済', QaThreadStatus::Resolved->label());
    }

    public function test_soft_deleting_author_retains_posts_and_returns_unsaved_unknown_user(): void
    {
        $thread = QaThread::factory()->create();
        $author = $thread->user;
        $reply = QaReply::factory()->for($thread, 'thread')->for($author, 'user')->create();

        $author->delete();

        foreach ([$thread->fresh()->user, $reply->fresh()->user] as $anonymous) {
            $this->assertSame('不明', $anonymous->name);
            $this->assertNull($anonymous->getKey());
            $this->assertFalse($anonymous->exists);
        }
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id, 'user_id' => $author->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id, 'user_id' => $author->id]);
    }
}
