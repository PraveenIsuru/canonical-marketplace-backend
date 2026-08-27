<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\NotifyNearbyAvailability;
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
        static::created(function (self $attachment): void {
            $attachment->store?->recomputeLiveFlag();

            /*
             * M8. Every path that creates an attachment is a shop starting to stock
             * something, so the nearby availability alert hangs off the model rather
             * than off any one controller: confirmation, the wizard, and an approved
             * proposal releasing a withheld listing all count, and a future path would
             * be covered without anyone remembering to add it.
             *
             * `afterCommit` because these events fire inside the surrounding
             * transaction, and a rolled back attachment must not have told anyone a
             * shop stocks something it does not.
             */
            NotifyNearbyAvailability::dispatch($attachment->id)->afterCommit();
        });

        static::deleted(fn (self $attachment) => $attachment->store?->recomputeLiveFlag());
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
