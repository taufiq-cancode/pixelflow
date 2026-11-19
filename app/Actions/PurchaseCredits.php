<?php

namespace App\Actions;

use App\Models\CreditPurchase;
use App\Models\User;
use App\Support\Money;

/**
 * Adds credits to a user's account. A fake purchase: no payment is taken.
 */
final class PurchaseCredits
{
    /**
     * The credit packs on offer, in whole US dollars.
     */
    public const PACKS_USD = [5, 10, 25];

    public function handle(User $user, int $dollars): CreditPurchase
    {
        return $user->creditPurchases()->create([
            'amount_nano' => $dollars * Money::NANOS_PER_DOLLAR,
        ]);
    }
}
