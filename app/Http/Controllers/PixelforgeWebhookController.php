<?php

namespace App\Http\Controllers;

use App\Actions\HandlePixelforgeWebhook;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/pixelforge (DECISIONS.md §14).
 *
 * Pixelforge treats any 2xx within 10 s as delivered and retries anything
 * else (after 10s, 30s, 2m, 10m, 15m). So:
 * - 400 for a bad signature or malformed event, with nothing stored;
 * - 200 once the event is safely committed (or was already handled);
 * - an unexpected error becomes a 500 and Pixelforge retries it later.
 */
final class PixelforgeWebhookController
{
    public function __invoke(
        Request $request,
        WebhookSignature $signature,
        HandlePixelforgeWebhook $handleWebhook,
    ): JsonResponse {
        // Verify against the raw body, before any JSON decoding.
        $payload = $request->getContent();

        if (! $signature->isValid($payload, $request->header('Pixelforge-Signature'))) {
            Log::warning('Rejected a Pixelforge webhook with an invalid signature.', [
                'event_id' => $request->header('Pixelforge-Event-Id'),
            ]);

            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! $this->isWellFormed($event)) {
            return response()->json(['error' => 'Malformed event.'], 400);
        }

        $attempt = $request->header('Pixelforge-Delivery-Attempt');

        $outcome = $handleWebhook->handle($event, ctype_digit((string) $attempt) ? (int) $attempt : null);

        return response()->json(['received' => true, 'outcome' => $outcome]);
    }

    /**
     * @param  array<mixed>  $event
     */
    private function isWellFormed(array $event): bool
    {
        $id = $event['id'] ?? null;
        $type = $event['type'] ?? null;

        // Pixelforge event ids are "evt_" + an opaque token. Be lenient about
        // the token (rejecting a real event would lose it after the retries)
        // but make sure it fits the unique event_id column.
        if (! is_string($id) || preg_match('/^evt_[A-Za-z0-9_-]{1,60}$/', $id) !== 1) {
            return false;
        }

        if (! is_string($type) || $type === '' || strlen($type) > 64) {
            return false;
        }

        if (! str_starts_with($type, 'generation.')) {
            return true;
        }

        $object = is_array($event['data'] ?? null) ? ($event['data']['object'] ?? null) : null;

        return is_array($object)
            && is_string($object['id'] ?? null)
            && is_string($object['status'] ?? null);
    }
}
