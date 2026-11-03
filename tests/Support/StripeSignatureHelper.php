<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Stripe Webhook の署名（Stripe-Signature ヘッダ）をテスト用に生成するヘルパー。
 */
class StripeSignatureHelper
{
    /**
     * 指定されたペイロードとシークレットから、Stripe-Signature ヘッダ文字列を生成する。
     *
     * @param string|array<string, mixed> $payload
     */
    public static function generate(string|array $payload, string $secret, ?int $timestamp = null, string $scheme = 'v1'): string
    {
        $payloadString = is_array($payload) ? (json_encode($payload, JSON_THROW_ON_ERROR) ?: '') : $payload;
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payloadString}", $secret);

        return "t={$timestamp},{$scheme}={$signature}";
    }
}
