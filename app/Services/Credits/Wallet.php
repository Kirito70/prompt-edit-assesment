<?php

namespace App\Services\Credits;

use App\Exceptions\InsufficientCredits;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Decimal;
use InvalidArgumentException;

/**
 * Moves a user's credits. Every method expects to run inside a database
 * transaction and locks the user row first, so concurrent requests cannot
 * spend the same credits twice.
 *
 * The balance only changes on a purchase and when a generation settles.
 * Unfinished generations hold part of it (reserved_credits), which is what
 * stops a user starting more than they can pay for.
 */
final class Wallet
{
    /**
     * Add a fake purchase. The amount comes from config, never from the request.
     */
    public function purchase(User $user, string $package): CreditTransaction
    {
        $amount = config("credits.packages.{$package}");

        if (! is_string($amount) || ! is_numeric($amount)) {
            throw new InvalidArgumentException("Unknown credit package {$package}.");
        }

        $locked = $this->lock($user);
        $amount = bcadd($amount, '0', Decimal::SCALE);

        return $this->record($locked, 'purchase', $amount, null);
    }

    /**
     * Check the user can afford $required on top of what they already hold,
     * and return the amount to hold. Throws when they cannot.
     *
     * With no price known ($required null) the user only needs some free credit,
     * and nothing is held.
     *
     * @param  numeric-string|null  $required  the highest the price could turn out to be
     * @return numeric-string
     *
     * @throws InsufficientCredits
     */
    public function assertCanAfford(User $user, ?string $required): string
    {
        $locked = $this->lock($user);
        $available = $locked->availableCredits();

        $short = $required === null
            ? bccomp($available, Decimal::ZERO, Decimal::SCALE) <= 0
            : bccomp($available, $required, Decimal::SCALE) < 0;

        if ($short) {
            throw new InsufficientCredits($available, $required ?? Decimal::ZERO);
        }

        return $required === null ? Decimal::ZERO : bcadd($required, '0', Decimal::SCALE);
    }

    /**
     * Take what a finished generation really cost, once. A repeat is a no-op:
     * the unique (generation, type) row refuses a second charge.
     */
    public function charge(Generation $generation): void
    {
        $charge = Decimal::fromDatabase($generation->customer_charge);

        if (bccomp($charge, Decimal::ZERO, Decimal::SCALE) <= 0) {
            return;
        }

        $already = CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', 'charge')
            ->exists();

        if ($already) {
            return;
        }

        $locked = $this->lock($generation->user);

        // The real cost can pass the held amount, so the balance may dip below zero.
        $this->record($locked, 'charge', bcmul($charge, '-1', Decimal::SCALE), $generation->id);
    }

    /**
     * @param  numeric-string  $amount  signed
     */
    private function record(User $locked, string $type, string $amount, ?string $generationId): CreditTransaction
    {
        $balance = bcadd(Decimal::fromDatabase($locked->balance), $amount, Decimal::SCALE);

        $locked->forceFill(['balance' => $balance])->save();

        return CreditTransaction::query()->create([
            'user_id' => $locked->id,
            'generation_id' => $generationId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balance,
        ]);
    }

    private function lock(User $user): User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }
}
