<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductAttributeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attribute definition, scoped to one product.
 *
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property array<int, string> $options
 * @property int $position
 */
class ProductAttribute extends Model
{
    /** @use HasFactory<ProductAttributeFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'name', 'options', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
