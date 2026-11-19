<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuyCreditsRequest;
use App\Services\Credits\Wallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CreditController extends Controller
{
    /**
     * A pretend purchase: adds the package's credits, takes no payment.
     */
    public function store(BuyCreditsRequest $request, Wallet $wallet): RedirectResponse
    {
        $transaction = DB::transaction(
            fn () => $wallet->purchase($request->user(), $request->string('package')->toString()),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Added :amount in credits.', ['amount' => '$'.rtrim(rtrim($transaction->amount, '0'), '.')]),
        ]);

        return back();
    }
}
