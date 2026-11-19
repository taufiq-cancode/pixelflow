<?php

namespace Tests\Feature\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class SubmitGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'pixelforge.test/v1/generations';

    /**
     * Runs the job with a fake queue job, so releases can be inspected.
     */
    private function runJob(Generation $generation, int $attempt = 1): SubmitGenerationJob
    {
        $job = (new SubmitGenerationJob($generation->id))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;

        app()->call([$job, 'handle']);

        return $job;
    }

    public function test_it_submits_with_the_idempotency_key_and_links_the_generation()
    {
        $generation = Generation::factory()->create(['reference_images' => ['https://example.com/style.png']]);
        Http::fake([self::ENDPOINT => Http::response(Pixelforge::generation('gen_NEW', 'queued'), 202)]);

        $this->runJob($generation)->assertNotReleased();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request['prompt'] === $generation->prompt
            && $request['size'] === '1024x1024'
            && $request['quality'] === 'medium'
            && $request['reference_images'] === ['https://example.com/style.png']
            && $request['metadata'] === ['pixelflow_generation_id' => (string) $generation->id, 'pixelflow_env' => 'testing']);

        $generation->refresh();
        $this->assertSame('gen_NEW', $generation->pixelforge_id);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame(Pixelforge::RATES, $generation->provider_rates);
        $this->assertSame(1, $generation->submit_attempts);
        $this->assertNotNull($generation->last_synced_at);
    }

    public function test_no_reference_images_means_the_parameter_is_omitted()
    {
        $generation = Generation::factory()->create(['reference_images' => []]);
        Http::fake([self::ENDPOINT => Http::response(Pixelforge::generation('gen_NEW', 'queued'), 202)]);

        $this->runJob($generation);

        Http::assertSent(fn (Request $request) => ! array_key_exists('reference_images', $request->data()));
    }

    public function test_an_idempotent_replay_is_applied_like_any_other_response()
    {
        // A retry after a lost response: Pixelforge returns the existing
        // generation as it is now.
        $generation = Generation::factory()->create();
        Http::fake([self::ENDPOINT => Http::response(Pixelforge::generation('gen_EXISTING', 'processing'), 202, ['Idempotent-Replayed' => 'true'])]);

        $this->runJob($generation);

        $generation->refresh();
        $this->assertSame('gen_EXISTING', $generation->pixelforge_id);
        $this->assertSame(GenerationStatus::Processing, $generation->status);
    }

    public function test_the_response_never_moves_a_generation_back_after_an_earlier_webhook()
    {
        // The processing webhook arrived (and linked the row) before the job
        // got to apply the 202 response, which still says "queued".
        $generation = Generation::factory()->create();
        $generation->forceFill(['pixelforge_id' => 'gen_RACE', 'status' => GenerationStatus::Processing])->save();

        app(ApplyGenerationSnapshot::class)->handle($generation->id, Pixelforge::generation('gen_RACE', 'queued'));

        $this->assertSame(GenerationStatus::Processing, $generation->fresh()?->status);
    }

    public function test_an_already_linked_generation_is_not_submitted_again()
    {
        $generation = Generation::factory()->submitted('processing')->create();
        Http::fake();

        $this->runJob($generation)->assertNotReleased();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{int, array<string, mixed>, array<string, string>, int}>
     */
    public static function retryableErrors(): array
    {
        return [
            'rate limited' => [429, Pixelforge::error('rate_limit_exceeded', 'rate_limit_error', 'Too many requests.'), ['Retry-After' => '7'], 7],
            'concurrency limit' => [429, Pixelforge::error('concurrency_limit_exceeded', 'rate_limit_error', 'Too many generations in flight.'), ['Retry-After' => '5'], 5],
            'overloaded' => [503, Pixelforge::error('overloaded', 'api_error', 'Pixelforge is temporarily overloaded.'), ['Retry-After' => '2'], 2],
            'unexpected server error' => [500, ['message' => 'Server Error'], [], 0],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    #[DataProvider('retryableErrors')]
    public function test_retryable_errors_release_the_job_no_sooner_than_retry_after(int $status, array $body, array $headers, int $retryAfter)
    {
        $generation = Generation::factory()->create();
        Http::fake([self::ENDPOINT => Http::response($body, $status, $headers)]);

        $job = $this->runJob($generation, attempt: 1);

        $job->assertReleased();
        // Backoff for the first attempt is 2 s, plus up to 2 s of jitter.
        $delay = $job->job->releaseDelay;
        $this->assertGreaterThanOrEqual(max($retryAfter, 2), $delay);
        $this->assertLessThanOrEqual(max($retryAfter, 2) + 2, $delay);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertNull($generation->pixelforge_id);
    }

    public function test_backoff_grows_with_each_attempt()
    {
        $generation = Generation::factory()->create();
        Http::fake([self::ENDPOINT => Http::response(Pixelforge::error('overloaded', 'api_error', 'Busy.'), 503, ['Retry-After' => '2'])]);

        $delay = $this->runJob($generation, attempt: 4)->job->releaseDelay;

        // 2^4 = 16 s, plus up to 2 s of jitter.
        $this->assertGreaterThanOrEqual(16, $delay);
        $this->assertLessThanOrEqual(18, $delay);
    }

    public function test_a_network_failure_is_retried()
    {
        $generation = Generation::factory()->create();
        Http::fake([self::ENDPOINT => Http::failedConnection()]);

        $this->runJob($generation)->assertReleased();

        $this->assertSame(GenerationStatus::Queued, $generation->fresh()?->status);
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string, string}>
     */
    public static function permanentErrors(): array
    {
        return [
            'content policy' => [400, Pixelforge::error('content_policy_violation', 'invalid_request_error', 'The prompt was rejected.'), 'content_policy_violation', 'content policy'],
            'daily quota' => [429, Pixelforge::error('daily_limit_exceeded', 'rate_limit_error', 'Daily quota used up.'), 'daily_limit_exceeded', 'daily generation limit'],
            'validation' => [422, Pixelforge::error('validation_failed', 'invalid_request_error', 'The selected size is invalid.'), 'validation_failed', 'The selected size is invalid.'],
            'bad api key' => [401, Pixelforge::error('invalid_api_key', 'authentication_error', 'Invalid API key.'), 'invalid_api_key', 'temporarily unavailable'],
            'idempotency key reused' => [409, Pixelforge::error('idempotency_key_reused', 'idempotency_error', 'Key reused.'), 'idempotency_key_reused', 'could not accept'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('permanentErrors')]
    public function test_permanent_errors_fail_the_generation_at_no_cost(int $status, array $body, string $code, string $messageFragment)
    {
        $generation = Generation::factory()->create();
        Http::fake([self::ENDPOINT => Http::response($body, $status)]);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame($code, $generation->error_code);
        $this->assertStringContainsString($messageFragment, (string) $generation->error_message);
        $this->assertStringContainsString('not charged', (string) $generation->error_message);
        $this->assertDatabaseCount('generation_costs', 0);
        Http::assertSentCount(1);
    }

    public function test_it_gives_up_after_the_last_attempt()
    {
        $generation = Generation::factory()->create();
        Http::fake([self::ENDPOINT => Http::response(Pixelforge::error('concurrency_limit_exceeded', 'rate_limit_error', 'Busy.'), 429, ['Retry-After' => '5'])]);

        $this->runJob($generation, attempt: 8)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('concurrency_limit_exceeded', $generation->error_code);
        $this->assertStringContainsString('too busy', (string) $generation->error_message);
        $this->assertDatabaseCount('generation_costs', 0);
    }

    public function test_the_failed_hook_marks_an_unsubmitted_generation_failed()
    {
        $generation = Generation::factory()->create();

        (new SubmitGenerationJob($generation->id))->failed(new RuntimeException('boom'));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('submission_failed', $generation->error_code);
    }

    public function test_the_failed_hook_leaves_a_generation_pixelforge_accepted_alone()
    {
        $generation = Generation::factory()->submitted('processing')->create();

        (new SubmitGenerationJob($generation->id))->failed(new RuntimeException('boom'));

        $this->assertSame(GenerationStatus::Processing, $generation->fresh()?->status);
    }
}
