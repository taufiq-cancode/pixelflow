<?php

namespace App\Enums;

/**
 * The lifecycle of a generation, identical to Pixelforge's:
 * queued → processing → succeeded | failed.
 *
 * Statuses only ever move forward. Each status has a rank, and a change is
 * applied only when the new rank is higher than the current one. Because
 * succeeded and failed share the top rank, neither can ever be overwritten,
 * and a late "processing" event after "succeeded" is simply ignored.
 */
enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Processing => 1,
            self::Succeeded, self::Failed => 2,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return $next->rank() > $this->rank();
    }

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Statuses of generations that have not finished yet.
     *
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::Queued, self::Processing];
    }
}
