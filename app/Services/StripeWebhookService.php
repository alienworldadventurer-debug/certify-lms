<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\PaymentIntent;

/**
 * Stripe Webhook イベントを Payment の状態と面談残数へ反映する。
 * 同じイベントが複数回届いても結果が変わらないよう、状態遷移は許可された組み合わせのみ行う。
 */
class StripeWebhookService
{
    public function handle(Event $event): void
    {
        match ($event->type) {
            'checkout.session.completed' => $this->onCheckoutCompleted($event->data->object),
            'checkout.session.expired',
            'checkout.session.async_payment_failed' => $this->markFailedBySession($event->data->object),
            'payment_intent.payment_failed' => $this->markFailedByPaymentIntent($event->data->object),
            'charge.refunded' => $this->onChargeRefunded($event->data->object),
            default => null,
        };
    }

    private function onCheckoutCompleted(Session $session): void
    {
        $paid = in_array($session->payment_status, ['paid', 'no_payment_required'], true);
        if (! $paid) {
            return;
        }

        DB::transaction(function () use ($session): void {
            $payment = Payment::query()
                ->where('stripe_checkout_session_id', $session->id)
                ->lockForUpdate()
                ->with('meetingPack')
                ->first();

            if ($payment === null) {
                Log::warning('未知の Checkout Session の完了通知を受信しました。', ['session_id' => $session->id]);

                return;
            }

            // 完了済み・返金済みへの再通知は何もしない(残数の二重加算防止)
            if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed], true)) {
                return;
            }

            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'quantity' => $payment->meetingPack->meeting_count,
                'stripe_payment_intent_id' => $session->payment_intent,
                'paid_at' => now(),
            ]);

            MeetingQuotaTransaction::create([
                'user_id' => $payment->user_id,
                'type' => MeetingQuotaTransactionType::Purchased,
                'amount' => $payment->quantity,
                'related_payment_id' => $payment->id,
                'occurred_at' => now(),
            ]);
        });
    }

    private function markFailedBySession(Session $session): void
    {
        $this->markFailed(
            Payment::query()->where('stripe_checkout_session_id', $session->id)
        );
    }

    private function markFailedByPaymentIntent(PaymentIntent $intent): void
    {
        $paymentId = $intent->metadata['payment_id'] ?? null;
        if ($paymentId === null) {
            return;
        }

        $this->markFailed(Payment::query()->whereKey($paymentId));
    }

    /**
     * @param Builder<Payment> $query
     */
    private function markFailed($query): void
    {
        DB::transaction(function () use ($query): void {
            $payment = (clone $query)->lockForUpdate()->first();
            if ($payment === null) {
                return;
            }

            // 完了・返金済みは失敗へ戻さない
            if ($payment->status === PaymentStatus::Pending) {
                $payment->update(['status' => PaymentStatus::Failed]);
            }
        });
    }

    private function onChargeRefunded(Charge $charge): void
    {
        // 部分返金は対象外。全額返金のみ残数から差し引く。
        if (! $charge->refunded || $charge->payment_intent === null) {
            return;
        }

        DB::transaction(function () use ($charge): void {
            $payment = Payment::query()
                ->where('stripe_payment_intent_id', $charge->payment_intent)
                ->lockForUpdate()
                ->first();

            if ($payment === null || $payment->status !== PaymentStatus::Succeeded) {
                return;
            }

            $payment->update(['status' => PaymentStatus::Refunded]);

            MeetingQuotaTransaction::create([
                'user_id' => $payment->user_id,
                'type' => MeetingQuotaTransactionType::Refunded,
                'amount' => -$payment->quantity,
                'related_payment_id' => $payment->id,
                'occurred_at' => now(),
            ]);
        });
    }
}
