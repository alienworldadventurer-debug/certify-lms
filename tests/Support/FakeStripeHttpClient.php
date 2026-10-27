<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Stripe API への実通信を置き換えるテスト用 HTTP クライアント。
 * 送信されたリクエストを記録し、固定の JSON を返す。
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, params: mixed}> */
    public array $requests = [];

    public bool $failCheckout = false;

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

        if (str_contains($absUrl, '/checkout/sessions')) {
            if ($this->failCheckout) {
                return [json_encode(['error' => ['type' => 'api_error', 'message' => 'boom']]), 500, []];
            }

            return [json_encode(['id' => 'cs_test_new', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/pay/cs_test_new']), 200, []];
        }

        if (str_contains($absUrl, '/customers')) {
            return [json_encode(['id' => 'cus_test', 'object' => 'customer']), 200, []];
        }

        return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'unexpected']]), 404, []];
    }

    public function getUserAgentInfo()
    {
        return [];
    }
}
