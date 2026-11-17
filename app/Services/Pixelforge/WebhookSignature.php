<?php

namespace App\Services\Pixelforge;

use Illuminate\Support\Facades\Date;

/**
 * Verifies the Pixelforge-Signature header (DECISIONS.md §14).
 *
 * The header is "t=<unix timestamp>,v1=<signature>", where the signature is
 * the hex HMAC-SHA256 of "<timestamp>.<raw request body>", keyed with the
 * signing secret (whsec_…) of the API key that created the generation.
 */
final class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds,
    ) {}

    /**
     * @param  string  $payload  the raw request body, byte for byte, before any JSON decoding
     */
    public function isValid(string $payload, ?string $header): bool
    {
        if ($this->secret === '' || $header === null || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        // Reject old (or far-future) timestamps so a captured request can't
        // be replayed later. Each delivery attempt is signed afresh.
        if (abs(Date::now()->getTimestamp() - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        // Sign the timestamp exactly as sent, not a re-formatted integer.
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        foreach ($signatures as $signature) {
            // Constant-time comparison: no timing leak of the expected value.
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
