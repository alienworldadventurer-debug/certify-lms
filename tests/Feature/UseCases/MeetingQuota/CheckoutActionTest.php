<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingQuota;

use App\Enums\PaymentStatus;
use App\Exceptions\MeetingQuota\MeetingPackNotPublishedException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\UseCases\MeetingQuota\CheckoutAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Stripe\StripeClient;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

class CheckoutActionTest extends TestCase
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

    public function test_starts_checkout_for_published_pack_and_returns_url(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $pack = MeetingPack::factory()->published()->create([
            'meeting_count' => 5,
            'price' => 10000,
        ]);

        $url = app(CheckoutAction::class)($user, $pack->id);

        $this->assertSame('https://checkout.stripe.com/pay/cs_test_new', $url);

        $payment = Payment::query()->firstOrFail();
        $this->assertSame($user->id, $payment->user_id);
        $this->assertSame($pack->id, $payment->meeting_pack_id);
        $this->assertSame(5, $payment->quantity);
        $this->assertSame(10000, $payment->amount);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('cs_test_new', $payment->stripe_checkout_session_id);
    }

    public function test_throws_when_pack_is_not_published(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $draft = MeetingPack::factory()->draft()->create();

        $this->expectException(MeetingPackNotPublishedException::class);
        app(CheckoutAction::class)($user, $draft->id);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_throws_when_pack_does_not_exist(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $nonExistentId = (string) Str::ulid();

        $this->expectException(ModelNotFoundException::class);
        app(CheckoutAction::class)($user, $nonExistentId);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_returns_null_when_stripe_api_fails(): void
    {
        $this->stripeHttp->failCheckout = true;

        $user = User::factory()->student()->inProgress()->create();
        $pack = MeetingPack::factory()->published()->create();

        $url = app(CheckoutAction::class)($user, $pack->id);

        $this->assertNull($url);
        $this->assertDatabaseCount('payments', 0);
    }
}
