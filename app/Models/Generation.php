<?php

namespace App\Models;

use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money columns are DECIMAL(20,12) and cast to strings; use BCMath for arithmetic.
 *
 * @property string $id
 * @property int $user_id
 * @property string $client_request_id
 * @property string|null $provider_generation_id
 * @property string $provider_idempotency_key
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property array<int, string> $reference_images
 * @property string $status queued|processing|succeeded|failed
 * @property string $submission_status pending|accepted|rejected|unknown
 * @property string $estimated_customer_charge
 * @property string|null $provider_cost
 * @property string|null $customer_charge
 * @property array<string, mixed>|null $rates
 * @property array<string, mixed>|null $usage
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $provider_completed_at
 * @property Carbon|null $settled_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_customer_charge' => 'decimal:12',
            'provider_cost' => 'decimal:12',
            'customer_charge' => 'decimal:12',
            'image_url_expires_at' => 'datetime',
            'provider_completed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
