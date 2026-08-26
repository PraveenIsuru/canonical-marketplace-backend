<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable snapshot in the version chain, which is also the audit record.
 *
 * No updated_at and no soft delete. A version is never edited once written, and a
 * rejected proposal produces no row here at all.
 *
 * @property int $id
 * @property int $product_id
 * @property int $version_number
 * @property array<string, mixed> $snapshot
 * @property bool $is_admin_originated
 */
class ProductVersion extends Model
{
    /** @use HasFactory<ProductVersionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'version_number',
        'snapshot',
        'proposal_id',
        'caused_by_store_id',
        'caused_by_user_id',
        'is_admin_originated',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'is_admin_originated' => 'boolean',
            'version_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function causedByStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'caused_by_store_id');
    }

    /** @return BelongsTo<User, $this> */
    public function causedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caused_by_user_id');
    }
}
