<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingPackStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\MeetingQuota\MeetingPackNotPublishedException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * 追加面談パックの Stripe Checkout Session を作成し、対応する Payment を pending で記録する。
 * 残数の加算は Webhook(StripeWebhookService) 側で行う。
 */
class StripeCheckoutService
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * @return string|null Stripe 決済画面 URL。API 失敗時は null(Payment は作成しない)。
     *
     * @throws MeetingPackNotPublishedException
     */
    public function startCheckout(User $user, MeetingPack $pack): ?string
    {
        if ($pack->status !== MeetingPackStatus::Published) {
            throw new MeetingPackNotPublishedException;
        }

        $paymentId = (string) Str::ulid();

        try {
            $customer = $this->stripe->customers->create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => ['user_id' => $user->id],
            ]);

            $session = $this->stripe->checkout->sessions->create([
                'mode' => 'payment',
                'customer' => $customer->id,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'jpy',
                        'unit_amount' => $pack->price,
                        'product_data' => ['name' => $pack->name],
                    ],
                ]],
                'success_url' => route('meeting-quota.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('meeting-quota.checkout', ['canceled' => 1]),
                'metadata' => ['payment_id' => $paymentId],
                'payment_intent_data' => ['metadata' => ['payment_id' => $paymentId]],
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Stripe Checkout Session の作成に失敗しました。', ['message' => $e->getMessage()]);

            return null;
        }

        // Stripe metadata に渡した ID と一致させるため、id は fillable 外のため個別に代入する
        $payment = new Payment([
            'user_id' => $user->id,
            'meeting_pack_id' => $pack->id,
            'quantity' => $pack->meeting_count,
            'amount' => $pack->price,
            'status' => PaymentStatus::Pending,
            'stripe_checkout_session_id' => $session->id,
        ]);
        $payment->id = $paymentId;
        $payment->save();

        return $session->url;
    }

    /**
     * 成功画面用。本人の Payment のみ返す(他人の session_id は null)。
     */
    public function findOwnPayment(User $user, string $sessionId): ?Payment
    {
        $payment = Payment::query()
            ->with('meetingPack')
            ->where('stripe_checkout_session_id', $sessionId)
            ->where('user_id', $user->id)
            ->first();

        if ($payment === null) {
            Log::warning('決済成功画面で対象の決済記録が見つかりませんでした。', ['user_id' => $user->id]);
        }

        return $payment;
    }
}
