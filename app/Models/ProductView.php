<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded look at a product page (EP-52).
 *
 * There is no created_at and no updated_at. `viewed_at` is the only time this row has,
 * because a view is an instant rather than a record with a life of its own, and a
 * second timestamp would only invite the two to disagree.
 *
 * `store_id` is the store the visitor arrived through, and is null far more often than
 * not: most catalogue traffic reaches a product directly rather than through anybody's
 * shop. `user_id` is null on every row this platform writes, because the endpoint is
 * public and public routes resolve no session.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $store_id
 * @property int|null $user_id
 * @property Carbon $viewed_at
 */
class ProductView extends Model
{
    /** @use HasFactory<ProductViewFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'store_id',
        'user_id',
        'viewed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
