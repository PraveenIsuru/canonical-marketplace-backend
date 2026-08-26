<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The canonical product record.
 *
 * No seller writes to this model. Ever. The only seller path into product data is a
 * proposal, so no seller facing controller exposes an update path here.
 *
 * There is no SoftDeletes trait and no delete path, because a product with no sellers
 * stays visible in the catalogue rather than disappearing.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $category
 * @property array<string, mixed> $specifications
 * @property int|null $current_version_id
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'specifications',
        'created_by_store_id',
    ];

    /**
     * created_by_store_id is hidden as well as internal.
     *
     * It is historical attribution only and conveys no ownership. Serialising it would
     * imply a seller owns the record, which is exactly what the design rejects.
     */
    protected $hidden = ['created_by_store_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'specifications' => 'array',
        ];
    }

    /** Public URLs are keyed by slug, not id. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'current_version_id');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(CommunitySummary::class);
    }
}
