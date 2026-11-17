<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Any failed Pixelforge request: an error response, or no response at all.
 */
final class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        // The HTTP status, or null when no response was received.
        public readonly ?int $status = null,
        // Pixelforge's error.code, e.g. "rate_limit_exceeded".
        public readonly ?string $errorCode = null,
        // Seconds from the Retry-After header, when Pixelforge sent one.
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');
        $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null;
        $message = is_array($error) && is_string($error['message'] ?? null)
            ? $error['message']
            : "Pixelforge returned HTTP {$response->status()}.";

        $retryAfter = $response->header('Retry-After');

        return new self(
            $message,
            $response->status(),
            $code,
            ctype_digit($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public static function connectionFailed(ConnectionException $exception): self
    {
        return new self('Could not reach Pixelforge: '.$exception->getMessage(), previous: $exception);
    }

    public static function unreadableResponse(Response $response): self
    {
        return new self("Pixelforge returned an unreadable body (HTTP {$response->status()}).", $response->status(), 'unreadable_response');
    }

    public static function missingApiKey(): self
    {
        return new self('PIXELFORGE_API_KEY is not configured.', 401, 'invalid_api_key');
    }

    /**
     * Whether sending the same request again can succeed.
     *
     * Per the docs, rate limits, the concurrency limit and overloads are
     * rejected before anything is created, so they are safe to retry. Network
     * failures and other server errors are ambiguous (the request may have
     * got through), but POST /generations is retried with the same
     * Idempotency-Key, so a retry returns the existing generation instead of
     * creating a second one. Everything else (content policy, validation,
     * daily quota, bad key) will fail the same way every time.
     */
    public function isRetryable(): bool
    {
        if ($this->status === null) {
            return true;
        }

        if ($this->status === 429) {
            return $this->errorCode !== 'daily_limit_exceeded';
        }

        return $this->status >= 500 || $this->errorCode === 'unreadable_response';
    }

    /**
     * A message that is safe and useful to show the user when a submission
     * is given up on. Failed submissions are never charged.
     */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. You were not charged.',
            'daily_limit_exceeded' => "Pixelforge's daily generation limit has been reached. Please try again after midnight UTC. You were not charged.",
            'validation_failed' => "Pixelforge rejected the request: {$this->getMessage()} You were not charged.",
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded' => 'Pixelforge was too busy to accept this generation after several attempts. You were not charged. Please try again.',
            'invalid_api_key' => 'Image generation is temporarily unavailable. You were not charged.',
            default => $this->status === null || $this->status >= 500
                ? 'Pixelforge could not be reached after several attempts. You were not charged. Please try again.'
                : 'Pixelforge could not accept this generation. You were not charged.',
        };
    }
}
