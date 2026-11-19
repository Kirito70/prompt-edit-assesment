<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a user's balance: a purchase (positive) or a charge (negative).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $generation_id
 * @property string $type
 * @property string $amount
 * @property string $balance_after
 * @property Carbon $created_at
 */
class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:12',
            'balance_after' => 'decimal:12',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
