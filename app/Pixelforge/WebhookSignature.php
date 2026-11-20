<?php

namespace App\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix>,v1=<hex>", where
 * v1 = HMAC-SHA256("<t>.<raw body>", signing secret).
 */
class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function isValid(string $payload, ?string $header, ?int $now = null): bool
    {
        if ($this->secret === '' || ! $header) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key][] = $value;
        }

        $timestamp = $parts['t'][0] ?? '';
        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > $this->toleranceSeconds) {
            return false; // missing or stale timestamp: possible replay
        }

        $expected = $this->sign($payload, (int) $timestamp);

        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function sign(string $payload, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);
    }

    public function header(string $payload, int $timestamp): string
    {
        return "t={$timestamp},v1=".$this->sign($payload, $timestamp);
    }
}
