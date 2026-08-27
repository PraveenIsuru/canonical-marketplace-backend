<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer watching one variant.
 *
 * Saved at **variant level, not product level**, because a price alert is only
 * meaningful for a specific combination. "Tell me when the phone gets cheaper" cannot
 * be acted on when the 128GB and the 256GB move independently.
 *
 * `last_notified_price_minor` is what stops a seller oscillating a price around a
 * threshold from sending an email on every downswing. Null means the buyer has never
 * been told about this variant, so the first drop always reaches them.
 *
 * @property int $id
 * @property int $user_id
 * @property int $variant_id
 * @property int|null $last_notified_price_minor
 */
class WishlistItem extends Model
{
    protected $fillable = ['user_id', 'variant_id', 'last_notified_price_minor'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Integer in the smallest currency unit, like every other price.
            'last_notified_price_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
