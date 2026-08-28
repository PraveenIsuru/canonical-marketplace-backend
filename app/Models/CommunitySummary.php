<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\InvalidatesCatalogueCache;
use Carbon\CarbonImmutable;
use Database\Factories\CommunitySummaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The AI generated sentiment summary. One per product, covering all variants together.
 *
 * @property int $id
 * @property int $product_id
 * @property string $summary_text
 * @property int $post_count_at_generation
 * @property CarbonImmutable $generated_at
 */
class CommunitySummary extends Model
{
    /** @use HasFactory<CommunitySummaryFactory> */
    use HasFactory, InvalidatesCatalogueCache;

    /**
     * A regenerated summary changes what EP-12 answers, and nothing else.
     *
     * The product's own cached reads carry it, so the product generation is what moves.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $summary) => self::catalogueCache()->forgetProduct($summary->product_id));
    }

    protected $fillable = ['product_id', 'summary_text', 'post_count_at_generation', 'generated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'post_count_at_generation' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
