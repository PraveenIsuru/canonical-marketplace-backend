<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\InvalidatesCatalogueCache;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An image belonging to the canonical record, shared by every store carrying it.
 *
 * @property int $id
 * @property int $product_id
 * @property string $storage_path
 * @property string $mime_type
 * @property int $file_size_bytes
 * @property int $position
 */
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory, InvalidatesCatalogueCache;

    /**
     * Images are part of both the product payload and its row in the catalogue listing,
     * so adding or removing one invalidates the same reads a record edit does.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $image) => self::catalogueCache()->forgetProduct($image->product_id));
        static::deleted(fn (self $image) => self::catalogueCache()->forgetProduct($image->product_id));
    }

    public $timestamps = false;

    /** Enforced in application logic, since a row count ceiling is not a column constraint. */
    public const MAX_PER_PRODUCT = 8;

    public const MAX_BYTES = 5242880;

    /** @var array<int, string> */
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    protected $fillable = [
        'product_id',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'uploaded_by_user_id',
        'position',
    ];

    /** The storage path is internal. Clients receive a URL instead. */
    protected $hidden = ['storage_path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'position' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function url(): string
    {
        return Storage::disk(config('filesystems.product_images', 'public'))->url($this->storage_path);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
