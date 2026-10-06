<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Database\Seeders\QaBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaBoardSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_three_questions_with_two_to_five_mixed_replies_and_is_idempotent(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $secondCoach = User::factory()->coach()->create();
        User::factory()->student()->count(4)->create();
        $basic = Certification::factory()->published()->create(['name' => '基本情報技術者試験']);
        $applied = Certification::factory()->published()->create(['name' => '応用情報技術者試験']);

        foreach ([[$basic, $coach], [$applied, $secondCoach]] as [$certification, $assignedCoach]) {
            CertificationCoachAssignment::factory()->create([
                'certification_id' => $certification->id,
                'user_id' => $assignedCoach->id,
                'assigned_by_user_id' => $admin->id,
            ]);
        }

        $seeder = app(QaBoardSeeder::class);
        $seeder->run();

        $threads = QaThread::query()->with('replies.user')->orderBy('title')->get();
        $this->assertCount(3, $threads);
        foreach ($threads as $thread) {
            $this->assertGreaterThanOrEqual(2, $thread->replies->count());
            $this->assertLessThanOrEqual(5, $thread->replies->count());
            $this->assertTrue($thread->replies->contains(fn ($reply) => $reply->user->role === UserRole::Coach));
            $this->assertTrue($thread->replies->contains(fn ($reply) => $reply->user->role === UserRole::Student));
        }

        $this->assertSame(2, $threads->where('status', QaThreadStatus::Open)->count());
        $this->assertSame(1, $threads->where('status', QaThreadStatus::Resolved)->count());
        $this->assertSame(2, $threads->where('certification_id', $basic->id)->count());
        $this->assertSame(1, $threads->where('certification_id', $applied->id)->count());
        $this->assertCount(3, $threads->pluck('user_id')->unique());

        $binarySearch = $threads->firstWhere('title', '2分探索の比較回数が log₂ n になるイメージをつかみたいです');
        $this->assertNotNull($binarySearch);
        $this->assertTrue($binarySearch->replies->contains(
            fn (QaReply $reply): bool => str_contains($reply->body, '8個なら最大4回、16個なら最大5回'),
        ));
        $this->assertTrue($binarySearch->replies->contains(
            fn (QaReply $reply): bool => str_contains($reply->body, 'floor(log₂ n) + 1'),
        ));

        $seeder->run();

        $this->assertDatabaseCount('qa_threads', 3);
        $this->assertSame(12, QaReply::query()->count());
    }
}
