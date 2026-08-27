<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A seller's store. One per user.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $category
 * @property string $contact_email
 * @property string|null $contact_phone
 * @property string $address_line
 * @property string $city
 *                        Null until geocoding succeeds or a pin is placed, which is why a store can be
 *                        registered before its location is known.
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $geocode_source
 * @property float|null $rating
 * @property bool $is_live
 */
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'category',
        'contact_email',
        'contact_phone',
        'address_line',
        'city',
        'latitude',
        'longitude',
        'geocode_source',
        'rating',
    ];

    /**
     * The PostGIS point is derived, never assigned by a caller, and never serialised.
     * It comes back from the database as binary WKB, which no client has any use for.
     */
    protected $hidden = ['location'];

    /**
     * is_live is deliberately not fillable. Visibility is derived from attachment
     * count and is set by recomputeLiveFlag(), never by a request payload.
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'rating' => 'float',
            'is_live' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Only live stores are visible to buyers.
     *
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_live', true);
    }

    /**
     * Updates the coordinates.
     *
     * The PostGIS point follows automatically: `location` is a generated column, so the
     * database derives it and the two can never disagree.
     */
    public function setCoordinates(float $latitude, float $longitude, string $source = 'locationiq'): void
    {
        $this->forceFill([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'geocode_source' => $source,
        ])->save();
    }

    /**
     * A store is visible if and only if it holds at least one attachment.
     *
     * Called whenever an attachment is created or deleted. The flag is stored rather
     * than computed because the alternative is a correlated subquery against the
     * largest table in the system on every product page render.
     */
    public function recomputeLiveFlag(): void
    {
        $shouldBeLive = $this->attachments()->exists();

        if ($this->is_live !== $shouldBeLive) {
            $this->forceFill(['is_live' => $shouldBeLive])->save();
        }
    }
}
