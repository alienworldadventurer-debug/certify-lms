<?php

declare(strict_types=1);

// 並行テスト用ワーカー。別プロセスで面談予約 (MeetingController::store) を 1 回実行し、結果を JSON で標準出力に返す。
// 引数: enrollment ULID, scheduled_at (Y-m-d H:i:s), 開始予定時刻 (microtime の秒, 全ワーカーで同一値)

use App\Models\Enrollment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $enrollmentId, $scheduledAt, $startAt] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(100);
}

try {
    $enrollment = Enrollment::query()->findOrFail($enrollmentId);
    Auth::setUser($enrollment->user);

    $request = Request::create(route('meetings.store', $enrollment, false), 'POST', [
        'scheduled_at' => $scheduledAt,
        'topic' => '並行予約テスト',
    ]);
    $request->setUserResolver(fn () => $enrollment->user);
    $app->instance('request', $request);
    $response = $app['router']->dispatch($request);
    // 予約成功は面談詳細 (/meetings/{id}) へのリダイレクトで判定する。失敗は別画面へ戻される。
    $location = (string) $response->headers->get('Location');
    echo json_encode([
        'result' => preg_match('#/meetings/[^/]+$#', $location) === 1 ? 'success' : 'rejected',
        'status' => $response->getStatusCode(),
        'location' => $location,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'result' => 'error',
        'class' => $e::class,
        'message' => $e->getMessage(),
    ]);
}
