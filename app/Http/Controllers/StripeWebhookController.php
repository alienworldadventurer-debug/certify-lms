<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\StripeWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Stripe からの Webhook を受信する。署名検証に失敗した場合は残数を変更せず 400 を返す。
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeWebhookService $service): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return response('Invalid payload or signature', 400);
        }

        $service->handle($event);

        return response('OK', 200);
    }
}
