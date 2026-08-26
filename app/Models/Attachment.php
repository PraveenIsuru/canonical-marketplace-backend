<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A store carrying a variant, at a price.
 *
 * @property int $id
 * @property int $store_id
 * @property int $variant_id
 * @property int $product_id
 * @property int $price_minor
 * @property string $currency
 * @property bool $is_available
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'variant_id',
        'product_id',
        'price_minor',
        'currency',
        'is_available',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Integer in the smallest currency unit. Never a float, at any point.
            'price_minor' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    /**
     * Store visibility follows attachment count, so both ends of the row lifecycle
     * recompute it.
     *
     * Hooking the model rather than each controller is what stops a future code path
     * from leaving a store dark when it has stock, or visible when it has none.
     */
    protected static function booted(): void
    {
        static::created(fn (self $attachment) => $attachment->store?->recomputeLiveFlag());
        static::deleted(fn (self $attachment) => $attachment->store?->recomputeLiveFlag());
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
