<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One message in a product's discussion.
 *
 * At **product level**, not variant level: owners of the 128GB and the 256GB are
 * talking about the same thing, and splitting the discussion by combination would give
 * every thread too few participants to be worth reading.
 *
 * There is no store column and no seller identity. A user who runs a store posts here
 * as a verified buyer like anyone else, which is the single account model working as
 * intended rather than an omission.
 *
 * Soft deleted rather than removed, so an administrator's moderation at M11 is
 * reversible and auditable. A deleted post takes its replies with it.
 *
 * @property int $id
 * @property int $product_id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $body
 */
class CommunityPost extends Model
{
    use SoftDeletes;

    protected $fillable = ['product_id', 'user_id', 'parent_id', 'body'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<CommunityPost, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Replies, one level deep.
     *
     * Threads do not nest further. A reply to a reply is refused, because a deep tree
     * on a product discussion is harder to read than a flat one and nobody asked for it.
     *
     * @return HasMany<CommunityPost, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Top level posts only.
     *
     * @param  Builder<CommunityPost>  $query
     * @return Builder<CommunityPost>
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
