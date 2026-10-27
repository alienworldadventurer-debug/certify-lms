<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\StripeClient;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripeHttp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripeHttp = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripeHttp);
        $this->app->instance(StripeClient::class, new StripeClient(['api_key' => 'sk_test_dummy']));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function student(): User
    {
        return User::factory()->student()->inProgress()->create();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('meeting-quota.checkout'))->assertRedirect(route('login'));
    }

    public function test_non_student_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('meeting-quota.checkout'))->assertForbidden();
    }

    public function test_student_not_in_progress_is_forbidden(): void
    {
        $student = User::factory()->student()->graduated()->create();

        $this->actingAs($student)->get(route('meeting-quota.checkout'))->assertForbidden();
    }

    public function test_index_lists_only_published_packs_in_sort_order(): void
    {
        $published = MeetingPack::factory()->published()->create(['name' => '公開パック', 'sort_order' => 1]);
        MeetingPack::factory()->draft()->create(['name' => '下書きパック']);

        $response = $this->actingAs($this->student())->get(route('meeting-quota.checkout'));

        $response->assertOk();
        $response->assertViewHas('plans', fn ($plans) => $plans->pluck('id')->all() === [$published->id]);
    }

    public function test_canceled_query_shows_cancel_message(): void
    {
        $response = $this->actingAs($this->student())->get(route('meeting-quota.checkout', ['canceled' => 1]));

        $response->assertOk();
        $response->assertSessionHas('info', 'お支払いをキャンセルしました。');
    }

    public function test_store_requires_meeting_pack_id(): void
    {
        $response = $this->actingAs($this->student())->post(route('meeting-quota.checkout.create'), []);

        $response->assertSessionHasErrors('meeting_pack_id');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_store_rejects_unpublished_pack_with_422(): void
    {
        $draft = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($this->student())->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $draft->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame([], $this->stripeHttp->requests);
    }

    public function test_store_creates_pending_payment_and_redirects_to_stripe(): void
    {
        $student = $this->student();
        $pack = MeetingPack::factory()->published()->create(['meeting_count' => 5, 'price' => 10000]);

        $response = $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $pack->id,
        ]);

        $response->assertRedirect('https://checkout.stripe.com/pay/cs_test_new');

        $payment = Payment::query()->firstOrFail();
        $this->assertSame($student->id, $payment->user_id);
        $this->assertSame($pack->id, $payment->meeting_pack_id);
        $this->assertSame(5, $payment->quantity);
        $this->assertSame(10000, $payment->amount);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('cs_test_new', $payment->stripe_checkout_session_id);
    }

    public function test_store_sends_pack_price_and_payment_id_metadata_to_stripe(): void
    {
        $pack = MeetingPack::factory()->published()->create(['meeting_count' => 3, 'price' => 7500, 'name' => '3 回パック']);

        $this->actingAs($this->student())->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $pack->id,
        ]);

        $sessionRequest = collect($this->stripeHttp->requests)->first(fn ($r) => str_contains($r['url'], '/checkout/sessions'));
        $paymentId = Payment::query()->firstOrFail()->id;

        $this->assertSame('payment', $sessionRequest['params']['mode']);
        $this->assertSame(7500, $sessionRequest['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('3 回パック', $sessionRequest['params']['line_items'][0]['price_data']['product_data']['name']);
        $this->assertSame($paymentId, $sessionRequest['params']['metadata']['payment_id']);
        $this->assertSame($paymentId, $sessionRequest['params']['payment_intent_data']['metadata']['payment_id']);
    }

    public function test_store_on_stripe_failure_returns_error_and_creates_no_payment(): void
    {
        $this->stripeHttp->failCheckout = true;
        $pack = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($this->student())->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $pack->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', '決済画面の作成に失敗しました。');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_success_page_shows_own_payment(): void
    {
        $student = $this->student();
        $payment = Payment::factory()->succeeded()->create([
            'user_id' => $student->id,
            'stripe_checkout_session_id' => 'cs_test_own',
        ]);

        $response = $this->actingAs($student)->get(route('meeting-quota.success', ['session_id' => 'cs_test_own']));

        $response->assertOk();
        $response->assertViewHas('payment', fn ($p) => $p?->id === $payment->id);
    }

    public function test_success_page_does_not_reveal_other_users_payment(): void
    {
        $other = User::factory()->student()->create();
        Payment::factory()->succeeded()->create([
            'user_id' => $other->id,
            'stripe_checkout_session_id' => 'cs_test_other',
        ]);

        $response = $this->actingAs($this->student())->get(route('meeting-quota.success', ['session_id' => 'cs_test_other']));

        $response->assertOk();
        $response->assertViewHas('payment', null);
    }

    public function test_success_page_without_session_id_returns_null_payment(): void
    {
        $response = $this->actingAs($this->student())->get(route('meeting-quota.success'));

        $response->assertOk();
        $response->assertViewHas('payment', null);
    }
}
