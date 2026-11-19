<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\CreditPurchase;
use App\Models\Generation;
use App\Models\GenerationCost;
use App\Models\User;
use App\Support\Money;

/**
 * A user's credits (US dollars), computed from the records rather than
 * kept in a column that could drift (DECISIONS.md §19):
 *
 *   balance   = purchases − charges for finished generations
 *   reserved  = the maximum estimate of every unfinished generation
 *   available = balance − reserved
 *
 * A generation is only accepted if its maximum estimate fits in the
 * available amount. Its actual cost is never more than that maximum, so a
 * balance can't go negative.
 */
final class CreditLedger
{
    public function balanceNano(User $user): int
    {
        $purchased = (int) CreditPurchase::query()
            ->where('user_id', $user->id)
            ->sum('amount_nano');

        $charged = (int) GenerationCost::query()
            ->whereIn('generation_id', Generation::query()->select('id')->where('user_id', $user->id))
            ->sum('user_cost_nano');

        return $purchased - $charged;
    }

    public function reservedNano(User $user): int
    {
        return (int) Generation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', GenerationStatus::inFlight())
            ->sum('estimated_max_cost_nano');
    }

    public function availableNano(User $user): int
    {
        return $this->balanceNano($user) - $this->reservedNano($user);
    }

    /**
     * What the UI shows, as exact decimal strings.
     *
     * @return array{balance_usd: string, reserved_usd: string, available_usd: string}
     */
    public function summary(User $user): array
    {
        $balance = $this->balanceNano($user);
        $reserved = $this->reservedNano($user);

        return [
            'balance_usd' => Money::format($balance),
            'reserved_usd' => Money::format($reserved),
            'available_usd' => Money::format($balance - $reserved),
        ];
    }
}
