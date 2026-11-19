<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Date;

/**
 * Pixelforge payloads shaped exactly like the examples in the API docs,
 * for faking responses and webhooks in tests.
 */
final class Pixelforge
{
    public const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * Default usage of a succeeded generation: 12×5,000 + 0 + 1,100×40,000
     * + 1,500×12,500 = 62,810,000 nanodollars from Pixelforge, so
     * 78,512,500 for the user after the 25% markup.
     */
    public const USAGE = [
        'input_text_tokens' => 12,
        'input_image_tokens' => 0,
        'output_tokens' => 1100,
        'reasoning_tokens' => 1500,
        'total_tokens' => 2612,
    ];

    public const PROVIDER_COST_NANO = 62_810_000;

    public const USER_COST_NANO = 78_512_500;

    /**
     * A generation object in the given status.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function generation(string $id, string $status, array $overrides = []): array
    {
        $generation = [
            'id' => $id,
            'object' => 'generation',
            'status' => $status,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => self::RATES,
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => null,
            'completed_at' => null,
        ];

        if ($status !== 'queued') {
            $generation['started_at'] = '2026-11-04T14:03:13Z';
        }

        if ($status === 'succeeded') {
            $generation['image_url'] = "https://pixelforge.test/images/{$id}.png?expires=1762351409&signature=abc";
            $generation['image_url_expires_at'] = '2026-11-05T14:03:29Z';
            $generation['usage'] = self::USAGE;
            $generation['completed_at'] = '2026-11-04T14:03:29Z';
        }

        if ($status === 'failed') {
            $generation['error'] = [
                'code' => 'internal_error',
                'message' => 'Something went wrong on our side while generating. You were not charged; please retry.',
            ];
            $generation['completed_at'] = '2026-11-04T14:03:29Z';
        }

        return array_replace($generation, $overrides);
    }

    /**
     * A webhook event wrapping a generation snapshot.
     *
     * @param  array<string, mixed>  $generation
     * @return array<string, mixed>
     */
    public static function event(string $eventId, string $type, array $generation): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => $generation],
        ];
    }

    /**
     * The Pixelforge-Signature header for a raw body.
     */
    public static function signature(string $payload, string $secret = 'whsec_test_secret', ?int $timestamp = null): string
    {
        $timestamp ??= Date::now()->getTimestamp();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }

    /**
     * An error response body.
     *
     * @return array<string, mixed>
     */
    public static function error(string $code, string $type, string $message): array
    {
        return ['error' => ['type' => $type, 'code' => $code, 'message' => $message]];
    }

    /**
     * The real GET /v1/pricing response (saved from the live API).
     *
     * @return array<string, mixed>
     */
    public static function pricing(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/pixelforge/pricing.json'), true);
    }
}
