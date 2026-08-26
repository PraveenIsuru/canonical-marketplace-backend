<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A generated variant combination.
 *
 * Permanent. There is no delete path here and none anywhere else in the application,
 * including for administrators.
 *
 * @property int $id
 * @property int $product_id
 * @property array<string, string> $attribute_values
 * @property string $combination_hash
 * @property bool $is_default
 *
 * Added by the variant query through selectSub, not columns on the table.
 * @property-read int|null $lowest_price_minor
 * @property-read int|null $seller_count
 */
class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['product_id', 'attribute_values', 'combination_hash', 'is_default'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attribute_values' => 'array',
            'is_default' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * A deterministic identity for a combination.
     *
     * Keys are sorted before hashing, so two combinations differing only in key order
     * hash the same. That is what makes the unique constraint mean anything, since
     * PostgreSQL cannot enforce uniqueness on JSONB content ignoring key order.
     *
     * @param  array<string, string>  $attributeValues
     */
    public static function hashCombination(array $attributeValues): string
    {
        ksort($attributeValues);

        return hash('sha256', (string) json_encode($attributeValues));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
