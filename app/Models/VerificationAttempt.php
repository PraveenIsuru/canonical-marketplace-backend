<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt by one user to prove they own one product.
 *
 * Scoped **per user per product**, and that scoping is the point rather than an
 * implementation detail: verifying a phone says nothing about whether you own the
 * laptop, and a platform that let one verification unlock every discussion would be
 * back to unverified comment threads with extra steps.
 *
 * **No column holds the photograph path.** The photograph is deleted the moment
 * verification concludes, on a pass and on a failure alike, and it lives only in the
 * queued job payload in between. `photo_deleted_at` records that the deletion
 * happened; `ai_reasoning` survives it, so a buyer can still be told why they failed
 * when deciding whether to spend another of their five attempts.
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property string $generated_code
 * @property int $attempt_number
 * @property string $outcome
 * @property string|null $ai_reasoning
 * @property Carbon|null $photo_deleted_at
 */
class VerificationAttempt extends Model
{
    /** Per user, per product. Reaching it closes the product to that user for good. */
    public const MAX_ATTEMPTS = 5;

    /** Started, code issued, photograph not yet submitted. Consumes no attempt. */
    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_PASSED = 'passed';

    public const OUTCOME_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'product_id',
        'generated_code',
        'attempt_number',
        'outcome',
        'ai_reasoning',
        'photo_deleted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'photo_deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** A concluded attempt, whichever way it went. Pending ones have not been spent. */
    public function isConcluded(): bool
    {
        return $this->outcome === self::OUTCOME_PASSED || $this->outcome === self::OUTCOME_FAILED;
    }
}
