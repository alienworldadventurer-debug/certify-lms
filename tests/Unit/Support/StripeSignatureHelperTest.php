<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\StripeSignatureHelper;

#[Group('external')]
class StripeSignatureHelperTest extends TestCase
{
    public function test_generates_valid_stripe_signature_header_string(): void
    {
        $payload = json_encode(['id' => 'evt_123', 'type' => 'test']);
        $secret = 'whsec_test_secret';
        $timestamp = 1700000000;

        $header = StripeSignatureHelper::generate($payload, $secret, $timestamp);

        $expectedSignature = hash_hmac('sha256', "1700000000.{$payload}", $secret);
        $this->assertSame("t=1700000000,v1={$expectedSignature}", $header);
    }

    public function test_accepts_array_payload_and_encodes_to_json(): void
    {
        $payload = ['id' => 'evt_456'];
        $secret = 'whsec_test_secret';
        $timestamp = 1700000000;

        $header = StripeSignatureHelper::generate($payload, $secret, $timestamp);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $expectedSignature = hash_hmac('sha256', "1700000000.{$json}", $secret);
        $this->assertSame("t=1700000000,v1={$expectedSignature}", $header);
    }
}
