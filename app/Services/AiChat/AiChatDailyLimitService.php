<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Models\AiChatDailyUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 受講生ごとの 1 日の送信回数を、上限判定と加算を同一トランザクション・行ロックで行う。
 *
 * 日付は Asia/Tokyo で切り替える。
 */
class AiChatDailyLimitService
{
    /**
     * @throws AiChatDailyLimitExceededException
     */
    public function consume(User $user): void
    {
        $limit = (int) config('ai-chat.daily_limit');
        $date = now('Asia/Tokyo')->toDateString();

        DB::transaction(function () use ($user, $date, $limit) {
            AiChatDailyUsage::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'usage_date' => $date,
                'count' => 0,
            ]);

            $usage = AiChatDailyUsage::query()
                ->where('user_id', $user->id)
                ->where('usage_date', $date)
                ->lockForUpdate()
                ->firstOrFail();

            if ($usage->count >= $limit) {
                throw new AiChatDailyLimitExceededException;
            }

            $usage->increment('count');
        });
    }
}
