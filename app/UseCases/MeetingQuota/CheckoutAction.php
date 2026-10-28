<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Exceptions\MeetingQuota\MeetingPackNotPublishedException;
use App\Models\MeetingPack;
use App\Models\User;
use App\Services\StripeCheckoutService;

/**
 * 追加面談パックの決済開始ユースケース。
 *
 * 面談パックを解決して Stripe Checkout の作成を依頼し、決済画面の URL を返す。
 * API 失敗時は null を返す(Payment は作成されない)。
 */
final class CheckoutAction
{
    public function __construct(
        private readonly StripeCheckoutService $service,
    ) {}

    /**
     * @return string|null Stripe 決済画面 URL
     *
     * @throws MeetingPackNotPublishedException
     */
    public function __invoke(User $user, string $meetingPackId): ?string
    {
        $pack = MeetingPack::query()->findOrFail($meetingPackId);

        return $this->service->startCheckout($user, $pack);
    }
}
