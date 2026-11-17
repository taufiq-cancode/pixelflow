<?php

namespace Tests\Unit;

use App\Enums\GenerationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenerationStatusTest extends TestCase
{
    /**
     * Every pair of statuses and whether moving from the first to the second
     * is allowed.
     *
     * @return array<string, array{GenerationStatus, GenerationStatus, bool}>
     */
    public static function transitions(): array
    {
        $queued = GenerationStatus::Queued;
        $processing = GenerationStatus::Processing;
        $succeeded = GenerationStatus::Succeeded;
        $failed = GenerationStatus::Failed;

        return [
            'queued → processing' => [$queued, $processing, true],
            'queued → succeeded (completed before processing arrived)' => [$queued, $succeeded, true],
            'queued → failed' => [$queued, $failed, true],
            'processing → succeeded' => [$processing, $succeeded, true],
            'processing → failed' => [$processing, $failed, true],
            'processing → queued' => [$processing, $queued, false],
            'succeeded → processing (late event)' => [$succeeded, $processing, false],
            'succeeded → queued' => [$succeeded, $queued, false],
            'succeeded → failed' => [$succeeded, $failed, false],
            'failed → succeeded' => [$failed, $succeeded, false],
            'failed → processing' => [$failed, $processing, false],
            'queued → queued' => [$queued, $queued, false],
            'processing → processing' => [$processing, $processing, false],
            'succeeded → succeeded (duplicate)' => [$succeeded, $succeeded, false],
            'failed → failed (duplicate)' => [$failed, $failed, false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_statuses_only_move_forward(GenerationStatus $from, GenerationStatus $to, bool $allowed)
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_only_succeeded_and_failed_are_terminal()
    {
        $this->assertFalse(GenerationStatus::Queued->isTerminal());
        $this->assertFalse(GenerationStatus::Processing->isTerminal());
        $this->assertTrue(GenerationStatus::Succeeded->isTerminal());
        $this->assertTrue(GenerationStatus::Failed->isTerminal());
    }
}
