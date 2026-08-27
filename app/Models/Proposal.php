<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A change to a canonical record awaiting peer review.
 *
 * The only path by which a seller affects product data. No seller writes to products,
 * attributes, or variants directly, ever.
 *
 * Two of these columns must never reach a client, on any endpoint, at any access
 * level: `confidence_score` and `confidence_band`. They decide the outcome server side
 * at M7, and showing them to a reviewer would anchor the vote on the AI's assessment
 * rather than on what the reviewer knows about the product. They are in `$hidden` as
 * a second line of defence, but the real guarantee is that no resource class selects
 * them, and a test asserts it.
 *
 * @property int $id
 * @property int $product_id
 * @property int $store_id
 * @property array<string, mixed> $changes
 * @property array<string, mixed> $ai_answers
 * @property string $status
 * @property Carbon $review_opens_at
 * @property Carbon $review_closes_at
 */
class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    /** Fixed platform wide. Not configurable per product or per category. */
    public const REVIEW_WINDOW_DAYS = 3;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ESCALATED = 'escalated';

    public const BAND_HIGH = 'high';

    public const BAND_LOW = 'low';

    protected $fillable = [
        'product_id',
        'store_id',
        'changes',
        'ai_answers',
        'confidence_score',
        'confidence_band',
        'status',
        'review_opens_at',
        'review_closes_at',
    ];

    /**
     * Hidden as well as never selected.
     *
     * A resource class is what actually decides a response body, so this does not by
     * itself keep the score off the wire. It is here so that a careless `toArray()` or
     * a debug dump in a controller does not become the leak.
     */
    protected $hidden = ['confidence_score', 'confidence_band'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'ai_answers' => 'array',
            'review_opens_at' => 'datetime',
            'review_closes_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The proposing store, which is blocked from selling this product until resolution. */
    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** The voter set, frozen when this proposal opened. */
    /** @return HasMany<ProposalReviewer, $this> */
    public function reviewers(): HasMany
    {
        return $this->hasMany(ProposalReviewer::class);
    }

    /**
     * Blocking a seller from selling the product.
     *
     * Escalated counts as blocking. A proposal that ran out of window with no votes is
     * still unresolved, and the seller is still waiting on an answer, so treating it as
     * finished would let them attach while the administrator is still deciding.
     *
     * @param  Builder<Proposal>  $query
     * @return Builder<Proposal>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_ESCALATED]);
    }

    public function isBlocking(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ESCALATED], true);
    }

    /**
     * The band the resolution matrix reads.
     *
     * Derived from the raw score at the threshold in configuration rather than being
     * asked of the provider, so the boundary can be retuned later without the past
     * meaning something different than it did. Both values are stored for that reason.
     */
    public static function bandFor(float $score): string
    {
        return $score >= (float) config('ai.confidence_high_threshold', 0.7)
            ? self::BAND_HIGH
            : self::BAND_LOW;
    }
}
