<?php

namespace App\Services\Pixelforge;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin wrapper around the Pixelforge REST API. Every method returns the
 * decoded JSON body or throws a PixelforgeException.
 *
 * Retry policy (DECISIONS.md §15):
 * - GET requests (pricing, poll, usage) retry here, up to 3 attempts,
 *   honouring Retry-After (capped at 10 s so a web request never hangs).
 * - POST /generations is never retried here. SubmitGenerationJob retries it
 *   from the queue with the same Idempotency-Key, so a long wait doesn't
 *   block anything and a retry can't create a second generation.
 */
final class PixelforgeClient
{
    private const GET_ATTEMPTS = 3;

    private const MAX_INLINE_WAIT_SECONDS = 10;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $connectTimeoutSeconds,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * GET /v1/pricing. Public: no API key needed.
     *
     * @return array<mixed>
     */
    public function pricing(): array
    {
        return $this->send(fn () => $this->request(authenticated: false, retry: true)->get('pricing'));
    }

    /**
     * POST /v1/generations. Returns the generation object (202 Accepted).
     *
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $payload));
    }

    /**
     * GET /v1/generations/{id}.
     *
     * @return array<mixed>
     */
    public function getGeneration(string $pixelforgeId): array
    {
        return $this->send(fn () => $this->request(retry: true)->get('generations/'.rawurlencode($pixelforgeId)));
    }

    /**
     * GET /v1/usage for an inclusive range of UTC dates (YYYY-MM-DD).
     *
     * @return array<mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->request(retry: true)->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    private function request(bool $authenticated = true, bool $retry = false): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->withUserAgent('PixelFlow/1.0');

        if ($authenticated) {
            if ($this->apiKey === '') {
                throw PixelforgeException::missingApiKey();
            }

            $request->withToken($this->apiKey);
        }

        if ($retry) {
            $request->retry(
                self::GET_ATTEMPTS,
                fn (int $attempt, Throwable $exception) => self::retryDelayMilliseconds($attempt, $exception),
                fn (Throwable $exception) => self::isRetryable($exception),
                throw: false,
            );
        }

        return $request;
    }

    /**
     * @param  Closure(): Response  $call
     * @return array<mixed>
     */
    private function send(Closure $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $exception) {
            throw PixelforgeException::connectionFailed($exception);
        }

        if (! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw PixelforgeException::unreadableResponse($response);
        }

        return $body;
    }

    private static function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        return PixelforgeException::fromResponse($exception->response)->isRetryable();
    }

    private static function retryDelayMilliseconds(int $attempt, Throwable $exception): int
    {
        $retryAfter = $exception instanceof RequestException
            ? PixelforgeException::fromResponse($exception->response)->retryAfterSeconds
            : null;

        $backoff = 2 ** ($attempt - 1); // 1 s, 2 s, ...

        return min(max($retryAfter ?? 0, $backoff), self::MAX_INLINE_WAIT_SECONDS) * 1000;
    }
}
