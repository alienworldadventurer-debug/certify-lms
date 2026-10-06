<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class QaPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_is_read_and_delete_only_regardless_of_question_state(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->resolved()->create();
        $reply = QaReply::factory()->for($thread, 'thread')->create();

        $gate = Gate::forUser($admin);

        foreach (['view', 'delete'] as $ability) {
            $this->assertTrue($gate->allows($ability, $thread));
            $this->assertTrue($gate->allows($ability, $reply));
        }
        $this->assertFalse($gate->allows('create', QaThread::class));
        $this->assertFalse($gate->allows('create', [QaReply::class, $thread]));
        foreach (['update', 'resolve', 'unresolve'] as $ability) {
            $this->assertFalse($gate->allows($ability, $thread));
        }
        $this->assertFalse($gate->allows('update', $reply));
    }

    public function test_hidden_resources_preserve_not_found_status_in_policy_response(): void
    {
        $thread = QaThread::factory()->create();
        $author = $thread->user;
        $reply = QaReply::factory()->for($thread, 'thread')->for($author, 'user')->create();
        $thread->certification->update(['status' => 'archived']);

        $gate = Gate::forUser($author);

        foreach (['view', 'update', 'delete', 'resolve', 'unresolve'] as $ability) {
            $response = $gate->inspect($ability, $thread->fresh());
            $this->assertFalse($response->allowed());
            $this->assertSame(404, $response->status());
        }
        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertSame(404, $gate->inspect($ability, $reply->fresh())->status());
        }
    }
}
