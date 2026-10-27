<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_dummy';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::SECRET]);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function postEvent(string $type, array $object, ?string $signature = null): TestResponse
    {
        $payload = json_encode([
            'id' => 'evt_test_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp = time();
        $header = $signature ?? sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET));

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload);
    }

    private function pendingPayment(int $quantity = 5, int $price = 10000): Payment
    {
        $pack = MeetingPack::factory()->published()->create(['meeting_count' => $quantity, 'price' => $price]);

        return Payment::factory()->pending()->create([
            'user_id' => User::factory()->student()->create()->id,
            'meeting_pack_id' => $pack->id,
            'quantity' => $quantity,
            'amount' => $price,
            'stripe_checkout_session_id' => 'cs_test_wh',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function completedSession(string $paymentIntent = 'pi_test_1'): array
    {
        return [
            'object' => 'checkout.session',
            'id' => 'cs_test_wh',
            'payment_status' => 'paid',
            'payment_intent' => $paymentIntent,
        ];
    }

    public function test_invalid_signature_returns_400_and_changes_nothing(): void
    {
        $payment = $this->pendingPayment();

        $response = $this->postEvent('checkout.session.completed', $this->completedSession(), 't=1,v1=invalid');

        $response->assertStatus(400);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_missing_signature_returns_400(): void
    {
        $this->pendingPayment();

        $response = $this->call('POST', route('webhooks.stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $response->assertStatus(400);
    }

    public function test_completed_session_marks_payment_succeeded_and_grants_purchased_quota(): void
    {
        $payment = $this->pendingPayment(quantity: 5);

        $this->postEvent('checkout.session.completed', $this->completedSession())->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame('pi_test_1', $payment->stripe_payment_intent_id);
        $this->assertNotNull($payment->paid_at);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $payment->user_id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 5,
            'related_payment_id' => $payment->id,
        ]);
    }

    public function test_quantity_is_updated_to_pack_meeting_count_on_completion(): void
    {
        $payment = $this->pendingPayment(quantity: 5);
        $payment->meetingPack->update(['meeting_count' => 8]);

        $this->postEvent('checkout.session.completed', $this->completedSession())->assertOk();

        $this->assertSame(8, $payment->fresh()->quantity);
        $this->assertDatabaseHas('meeting_quota_transactions', ['related_payment_id' => $payment->id, 'amount' => 8]);
    }

    public function test_duplicate_completed_event_does_not_grant_quota_twice(): void
    {
        $payment = $this->pendingPayment(quantity: 5);

        $this->postEvent('checkout.session.completed', $this->completedSession())->assertOk();
        $this->postEvent('checkout.session.completed', $this->completedSession())->assertOk();

        $this->assertSame(1, MeetingQuotaTransaction::query()->where('related_payment_id', $payment->id)->count());
    }

    public function test_unpaid_session_is_ignored(): void
    {
        $payment = $this->pendingPayment();

        $session = array_merge($this->completedSession(), ['payment_status' => 'unpaid']);
        $this->postEvent('checkout.session.completed', $session)->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_completion_after_failure_still_grants_quota(): void
    {
        $payment = $this->pendingPayment(quantity: 5);
        $payment->update(['status' => PaymentStatus::Failed]);

        $this->postEvent('checkout.session.completed', $this->completedSession())->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertDatabaseHas('meeting_quota_transactions', ['related_payment_id' => $payment->id, 'amount' => 5]);
    }

    public function test_unknown_session_returns_200_without_changes(): void
    {
        $this->pendingPayment();

        $this->postEvent('checkout.session.completed', array_merge($this->completedSession(), ['id' => 'cs_unknown']))->assertOk();

        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_expired_session_marks_pending_payment_failed(): void
    {
        $payment = $this->pendingPayment();

        $this->postEvent('checkout.session.expired', ['object' => 'checkout.session', 'id' => 'cs_test_wh'])->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_async_payment_failed_marks_pending_payment_failed(): void
    {
        $payment = $this->pendingPayment();

        $this->postEvent('checkout.session.async_payment_failed', ['object' => 'checkout.session', 'id' => 'cs_test_wh'])->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
    }

    public function test_payment_intent_failed_marks_pending_payment_failed_by_metadata(): void
    {
        $payment = $this->pendingPayment();

        $this->postEvent('payment_intent.payment_failed', [
            'object' => 'payment_intent',
            'id' => 'pi_test_fail',
            'metadata' => ['payment_id' => $payment->id],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
    }

    public function test_failure_event_does_not_revert_succeeded_payment(): void
    {
        $payment = $this->pendingPayment();
        $this->postEvent('checkout.session.completed', $this->completedSession())->assertOk();

        $this->postEvent('checkout.session.expired', ['object' => 'checkout.session', 'id' => 'cs_test_wh'])->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
    }

    public function test_full_refund_marks_payment_refunded_and_removes_quota(): void
    {
        $payment = $this->pendingPayment(quantity: 5);
        $this->postEvent('checkout.session.completed', $this->completedSession())->assertOk();

        $this->postEvent('charge.refunded', [
            'object' => 'charge',
            'refunded' => true,
            'payment_intent' => 'pi_test_1',
        ])->assertOk();

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => -5,
        ]);
    }

    public function test_partial_refund_is_ignored(): void
    {
        $payment = $this->pendingPayment(quantity: 5);
        $this->postEvent('checkout.session.completed', $this->completedSession())->assertOk();

        $this->postEvent('charge.refunded', [
            'object' => 'charge',
            'refunded' => false,
            'payment_intent' => 'pi_test_1',
        ])->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_refund_is_not_applied_twice(): void
    {
        $payment = $this->pendingPayment(quantity: 5);
        $this->postEvent('checkout.session.completed', $this->completedSession())->assertOk();

        $refund = ['object' => 'charge', 'refunded' => true, 'payment_intent' => 'pi_test_1'];
        $this->postEvent('charge.refunded', $refund)->assertOk();
        $this->postEvent('charge.refunded', $refund)->assertOk();

        $this->assertSame(1, MeetingQuotaTransaction::query()
            ->where('related_payment_id', $payment->id)
            ->where('type', MeetingQuotaTransactionType::Refunded)
            ->count());
    }

    public function test_unhandled_event_type_returns_200(): void
    {
        $payment = $this->pendingPayment();

        $this->postEvent('customer.created', ['object' => 'customer', 'id' => 'cus_x'])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }
}
