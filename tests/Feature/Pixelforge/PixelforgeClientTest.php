<?php

namespace Tests\Feature\Pixelforge;

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    private function client(string $apiKey = 'pf_test_key'): PixelforgeClient
    {
        return new PixelforgeClient('https://pixelforge.test/v1', $apiKey, 5, 15);
    }

    public function test_get_requests_retry_after_a_rate_limit_and_honour_retry_after()
    {
        Sleep::fake();
        Http::fake(['pixelforge.test/v1/generations/gen_1' => Http::sequence()
            ->push(Pixelforge::error('rate_limit_exceeded', 'rate_limit_error', 'Slow down.'), 429, ['Retry-After' => '3'])
            ->push(Pixelforge::generation('gen_1', 'processing'), 200)]);

        $generation = $this->client()->getGeneration('gen_1');

        $this->assertSame('processing', $generation['status']);
        Http::assertSentCount(2);
        Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalMilliseconds === 3000);
    }

    public function test_get_requests_cap_the_inline_wait()
    {
        Sleep::fake();
        Http::fake(['pixelforge.test/v1/generations/gen_1' => Http::sequence()
            ->push(Pixelforge::error('rate_limit_exceeded', 'rate_limit_error', 'Slow down.'), 429, ['Retry-After' => '45'])
            ->push(Pixelforge::generation('gen_1', 'processing'), 200)]);

        $this->client()->getGeneration('gen_1');

        Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalMilliseconds === 10_000);
    }

    public function test_get_requests_give_up_after_three_attempts()
    {
        Sleep::fake();
        Http::fake(['pixelforge.test/v1/generations/gen_1' => Http::response(Pixelforge::error('overloaded', 'api_error', 'Busy.'), 503, ['Retry-After' => '2'])]);

        try {
            $this->client()->getGeneration('gen_1');
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame('overloaded', $exception->errorCode);
            $this->assertSame(2, $exception->retryAfterSeconds);
            $this->assertTrue($exception->isRetryable());
        }

        Http::assertSentCount(3);
    }

    public function test_create_is_never_retried_inside_the_client()
    {
        Http::fake(['pixelforge.test/v1/generations' => Http::response(Pixelforge::error('overloaded', 'api_error', 'Busy.'), 503, ['Retry-After' => '2'])]);

        $this->expectException(PixelforgeException::class);

        try {
            $this->client()->createGeneration(['prompt' => 'x'], 'key-1');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_a_daily_quota_error_is_not_retryable()
    {
        Sleep::fake();
        Http::fake(['pixelforge.test/v1/generations/gen_1' => Http::response(Pixelforge::error('daily_limit_exceeded', 'rate_limit_error', 'Quota used.'), 429)]);

        try {
            $this->client()->getGeneration('gen_1');
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException $exception) {
            $this->assertFalse($exception->isRetryable());
        }

        Http::assertSentCount(1);
    }

    public function test_a_network_failure_becomes_a_retryable_exception_without_a_status()
    {
        Http::fake(['pixelforge.test/v1/generations' => Http::failedConnection()]);

        try {
            $this->client()->createGeneration(['prompt' => 'x'], 'key-1');
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException $exception) {
            $this->assertNull($exception->status);
            $this->assertTrue($exception->isRetryable());
        }
    }

    public function test_a_missing_api_key_fails_without_sending_anything()
    {
        Http::fake();

        try {
            $this->client(apiKey: '')->createGeneration(['prompt' => 'x'], 'key-1');
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException $exception) {
            $this->assertSame('invalid_api_key', $exception->errorCode);
            $this->assertFalse($exception->isRetryable());
        }

        Http::assertNothingSent();
    }

    public function test_pricing_needs_no_api_key()
    {
        Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::pricing())]);

        $pricing = $this->client(apiKey: '')->pricing();

        $this->assertSame('12.50', $pricing['rates']['reasoning_tokens']);
        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }

    public function test_usage_sends_the_date_range()
    {
        Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => [], 'total' => []])]);

        $this->client()->usage('2026-11-01', '2026-11-02');

        Http::assertSent(fn ($request) => $request['start_date'] === '2026-11-01' && $request['end_date'] === '2026-11-02');
    }
}
