<?php

namespace App\Http\Controllers;

use App\Events\GenerationUpdated;
use App\Http\Requests\BuyCreditsRequest;
use App\Models\CreditTransaction;
use App\Services\Credits\Wallet;
use App\Support\Decimal;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * The user's credit history: purchases and charges, newest first.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('credits/index', [
            'balance' => Decimal::fromDatabase($user->balance),
            // Live: a finished generation adds a charge row.
            'transactions' => Inertia::live(
                fn () => $user->creditTransactions()
                    ->latest('created_at')
                    ->latest('id')
                    ->paginate(self::PER_PAGE)
                    ->through(fn (CreditTransaction $transaction): array => [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'amount' => $transaction->amount,
                        'balance_after' => $transaction->balance_after,
                        'generation_id' => $transaction->generation_id,
                        'created_at' => $transaction->created_at->toIso8601String(),
                    ]),
                on: GenerationUpdated::class,
                channel: new PrivateChannel("users.{$user->id}.generations"),
            ),
        ]);
    }

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
