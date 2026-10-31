<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 同一コーチ・同一時刻の面談予約を 2 プロセスから同時に実行し、ダブルブッキングが起きないことを検証する。
 *
 * 並行性を再現するため RefreshDatabase(トランザクション包み) は使わず、実 MySQL (InnoDB) に対して実行する。
 * テストは MySQL 接続が前提(SQLite では行ロックが効かず検証にならない)。
 */
class MeetingConcurrentBookingTest extends TestCase
{
    use DatabaseMigrations;

    private const ROUNDS = 5;

    public function test_concurrent_store_never_double_books_same_coach_and_slot(): void
    {
        $this->assertSame('mysql', config('database.default'), 'このテストは MySQL で実行すること');

        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();

        $enrollments = [];
        for ($i = 0; $i < 2; $i++) {
            $student = User::factory()->student()->inProgress()->create(['max_meetings' => self::ROUNDS]);
            $enrollments[] = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        }

        $monday = now()->startOfDay()->next(Carbon::MONDAY);
        $doubleBookings = [];

        for ($round = 0; $round < self::ROUNDS; $round++) {
            $scheduledAt = $monday->copy()->addWeeks($round)->setTime(10, 0);
            $results = $this->runConcurrentBookings($enrollments, $scheduledAt);

            $successCount = collect($results)->where('result', 'success')->count();
            $reservedCount = Meeting::query()
                ->where('coach_id', $coach->id)
                ->where('status', MeetingStatus::Reserved->value)
                ->where('scheduled_at', $scheduledAt)
                ->count();

            if ($successCount !== 1 || $reservedCount !== 1) {
                $doubleBookings[] = sprintf(
                    'round=%d scheduled_at=%s success=%d reserved=%d results=%s',
                    $round,
                    $scheduledAt->format('Y-m-d H:i'),
                    $successCount,
                    $reservedCount,
                    json_encode($results, JSON_UNESCAPED_UNICODE),
                );
            }
        }

        $this->assertSame(
            [],
            $doubleBookings,
            '同一コーチ・同一時刻に対して並行予約が成立してはならない(成立した枠を列挙)',
        );
    }

    /**
     * @param  list<Enrollment>  $enrollments
     * @return list<array<string, mixed>>
     */
    private function runConcurrentBookings(array $enrollments, Carbon $scheduledAt): array
    {
        $worker = base_path('tests/Support/MeetingStoreWorker.php');
        $startAt = microtime(true) + 5.0;

        $processes = [];
        foreach ($enrollments as $enrollment) {
            $process = new Process([
                PHP_BINARY,
                $worker,
                $enrollment->id,
                $scheduledAt->format('Y-m-d H:i:s'),
                (string) $startAt,
            ]);
            $process->setTimeout(60);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $decoded = json_decode($process->getOutput(), true);
            $results[] = is_array($decoded)
                ? $decoded
                : ['result' => 'crashed', 'stderr' => $process->getErrorOutput()];
        }

        return $results;
    }
}
