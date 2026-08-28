<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\InvalidatesCatalogueCache;
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
    use HasFactory, InvalidatesCatalogueCache;

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
            self::forgetCachedCatalogue($attachment);

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

        /*
         * A price or availability edit changes the lowest price on the product page, on
         * the variant list, and on the store's own page, so an update invalidates
         * exactly what a creation does. It does not touch the live flag: the row still
         * exists, so the shelf is still stocked.
         */
        static::updated(fn (self $attachment) => self::forgetCachedCatalogue($attachment));

        static::deleted(function (self $attachment): void {
            $attachment->store?->recomputeLiveFlag();
            self::forgetCachedCatalogue($attachment);
        });
    }

    /**
     * The reads an attachment write makes wrong.
     *
     * Three of them, and it is worth naming why each one. The **product** carries a
     * seller count and its variants carry a lowest price. The **store** page lists what
     * it stocks and at what price. The **catalogue listing** shows both figures for the
     * product in a row of its own, and that one goes with the product.
     */
    private static function forgetCachedCatalogue(self $attachment): void
    {
        $cache = self::catalogueCache();

        $cache->forgetProduct($attachment->product_id);
        $cache->forgetStore($attachment->store_id);
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
