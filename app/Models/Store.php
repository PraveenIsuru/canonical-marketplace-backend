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
use Illuminate\Support\Facades\DB;

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
 * @property float $latitude
 * @property float $longitude
 * @property string $geocode_source
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** Only live stores are visible to buyers. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_live', true);
    }

    /**
     * Derives the PostGIS point from the coordinate pair on every save.
     *
     * Done here rather than at each call site so the two can never disagree. A store
     * created by a factory, by the seeder, or by the registration endpoint all get a
     * correct point without any of them remembering to build one.
     *
     * ST_MakePoint takes longitude first. Reversing the pair is the classic geospatial
     * bug and would put every Sri Lankan store somewhere off the coast of Somalia.
     */
    protected static function booted(): void
    {
        static::saving(function (self $store): void {
            if (! $store->isDirty(['latitude', 'longitude'])) {
                return;
            }

            $store->setAttribute('location', DB::raw(sprintf(
                'ST_SetSRID(ST_MakePoint(%.8F, %.8F), 4326)::geography',
                (float) $store->longitude,
                (float) $store->latitude,
            )));
        });
    }

    /** Updates the coordinates. The point follows automatically on save. */
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
