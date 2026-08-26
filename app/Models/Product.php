<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;

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
 *
 * These two are not columns. They are added by the catalogue and variant queries
 * through selectSub, and are absent on a plainly loaded model.
 * @property-read int|null $lowest_price_minor
 * @property-read int|null $seller_count
 * @property-read ProductVersion|null $currentVersion
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, Searchable;

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

    /** @return HasMany<ProductAttribute, $this> */
    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('position');
    }

    /** @return HasMany<Variant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasMany<ProductVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class)->orderByDesc('version_number');
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'current_version_id');
    }

    /** @return HasOne<CommunitySummary, $this> */
    public function summary(): HasOne
    {
        return $this->hasOne(CommunitySummary::class);
    }

    /**
     * What goes into the search index.
     *
     * Deliberately only the stable, descriptive fields. Price, seller count, and
     * availability are excluded even though a buyer might search on them, because they
     * change whenever any seller edits a listing and indexing them would mean
     * reindexing the catalogue constantly for data Postgres already answers better.
     *
     * The search engine answers "which products match this query". The database answers
     * "which sellers carry it, how far away, at what price". Keeping that split is what
     * makes the index cheap to maintain.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            // Values only. The keys are attribute names like "Display", which a buyer
            // would never type, and indexing them dilutes relevance.
            'specifications' => implode(' ', array_map(
                static fn ($value): string => is_scalar($value) ? (string) $value : '',
                array_values($this->specifications ?? []),
            )),
        ];
    }

    /** Scout uses the primary key by default; stated so the index id is unambiguous. */
    public function getScoutKey(): mixed
    {
        return $this->id;
    }

    public function getScoutKeyName(): string
    {
        return 'id';
    }
}
