<?php

namespace App\Http\Controllers;

use App\Exceptions\BalanceCapExceededException;
use App\Services\CreditLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /** Fake purchase packages, in whole dollars. */
    public const PACKAGES = [1, 5, 10, 25];

    public function index(Request $request): Response
    {
        return Inertia::render('Credits/Index', [
            'packages' => self::PACKAGES,
            'maxBalance' => (int) config('pixelforge.limits.max_balance_dollars'),
            'transactions' => $request->user()->creditTransactions()->latest('id')->paginate(20)
                ->through(fn ($t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'description' => $t->description,
                    'amount' => Money::nanoToDecimal($t->amount_nano),
                    'generation_id' => $t->generation_id,
                    'created_at' => $t->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'in:'.implode(',', self::PACKAGES)]]);

        $amountNano = $data['amount'] * Money::NANO_PER_DOLLAR;
        $maxDollars = (int) config('pixelforge.limits.max_balance_dollars');

        // Credits are free in this demo, so cap them: otherwise one account could buy unlimited
        // credits and run up unlimited Pixelforge costs.
        try {
            $ledger->purchase($request->user(), $amountNano, $maxDollars * Money::NANO_PER_DOLLAR);
        } catch (BalanceCapExceededException) {
            return back()->withErrors(['amount' => "Balances are capped at \${$maxDollars} in this demo."]);
        }

        return back()->with('success', "Added \${$data['amount']}.00 in credits.");
    }
}
