<?php

declare(strict_types=1);

namespace Tests\Feature\Services\AiChat;

use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Models\AiChatDailyUsage;
use App\Models\User;
use App\Services\AiChat\AiChatDailyLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AiChatDailyLimitService の上限判定と加算を検証する。
 */
class AiChatDailyLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-chat.daily_limit' => 2]);
    }

    public function test_first_consume_creates_usage_row_with_count_one(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        app(AiChatDailyLimitService::class)->consume($user);

        $this->assertSame(1, AiChatDailyUsage::query()
            ->where('user_id', $user->id)
            ->where('usage_date', now('Asia/Tokyo')->toDateString())
            ->value('count'));
    }

    public function test_consume_throws_when_limit_is_reached(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $service = app(AiChatDailyLimitService::class);

        $service->consume($user);
        $service->consume($user);

        $this->expectException(AiChatDailyLimitExceededException::class);

        $service->consume($user);
    }

    public function test_limit_is_counted_per_user(): void
    {
        $first = User::factory()->student()->inProgress()->create();
        $second = User::factory()->student()->inProgress()->create();
        $service = app(AiChatDailyLimitService::class);

        $service->consume($first);
        $service->consume($first);
        $service->consume($second);

        $this->assertSame(1, AiChatDailyUsage::query()->where('user_id', $second->id)->value('count'));
    }

    public function test_usage_is_counted_by_tokyo_date(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        AiChatDailyUsage::query()->create([
            'user_id' => $user->id,
            'usage_date' => now('Asia/Tokyo')->subDay()->toDateString(),
            'count' => 2,
        ]);

        app(AiChatDailyLimitService::class)->consume($user);

        $this->assertSame(1, AiChatDailyUsage::query()
            ->where('user_id', $user->id)
            ->where('usage_date', now('Asia/Tokyo')->toDateString())
            ->value('count'));
    }
}
