<?php

namespace App\Http\Controllers;

use App\Actions\PurchaseCredits;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CreditController
{
    /**
     * The Credits page: balance (shared on every page), packs and the
     * user's purchases and charges.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $purchases = $user->creditPurchases()->latest('id')->limit(20)->get()->map(fn ($purchase) => [
            'id' => $purchase->id,
            'amount_usd' => Money::format($purchase->amount_nano),
            'created_at' => $purchase->created_at?->toIso8601String(),
        ]);

        return Inertia::render('credits/Index', [
            'packs' => PurchaseCredits::PACKS_USD,
            'purchases' => $purchases,
        ]);
    }

    /**
     * "Buy credits": a fake purchase, no payment is taken.
     */
    public function store(Request $request, PurchaseCredits $purchaseCredits): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', Rule::in(PurchaseCredits::PACKS_USD)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $purchaseCredits->handle($user, (int) $validated['amount']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added \${$validated['amount']}.00 in credits."]);

        return back();
    }
}
