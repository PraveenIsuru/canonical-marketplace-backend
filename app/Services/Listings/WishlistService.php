<?php

declare(strict_types=1);

namespace App\Services\Listings;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Variant;
use App\Models\WishlistItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A buyer's saved variants (EP-36, EP-37, EP-38).
 *
 * Saved per variant rather than per product, because both alerts that read this table
 * are about a specific combination. "Tell me when the phone gets cheaper" cannot be
 * acted on when the 128GB and the 256GB move independently.
 *
 * This is buyer level, not seller level. A user who happens to run a store keeps their
 * own wishlist like anyone else, which is the single account model working as intended.
 */
final class WishlistService
{
    /**
     * What this buyer is watching, with the current cheapest listing for each.
     *
     * @return LengthAwarePaginator<int, WishlistItem>
     */
    public function forUser(User $user, int $perPage): LengthAwarePaginator
    {
        return WishlistItem::query()
            ->where('user_id', $user->id)
            /*
             * Eager loaded three deep because every row renders a product name, an
             * image, and the combination. Without this the screen is a query per row
             * and then some.
             */
            ->with(['variant.product.images', 'variant.attachments'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Saves a variant.
     *
     * **A repeat save is not an error.** A buyer pressing the button twice has
     * expressed the same intent twice, and answering 409 would make the interface
     * apologise for something nobody did wrong. The unique index on
     * (user_id, variant_id) is what makes this safe rather than the check.
     *
     * Any variant can be saved, including one no seller carries. That is the case the
     * nearby availability alert exists for.
     */
    public function add(User $user, int $variantId): WishlistItem
    {
        $variant = Variant::find($variantId)
            ?? throw ApiException::notFound('That version does not exist.');

        $item = WishlistItem::firstOrCreate([
            'user_id' => $user->id,
            'variant_id' => $variant->id,
        ]);

        return $item->load(['variant.product.images', 'variant.attachments']);
    }

    /**
     * Removes a saved variant, by the wishlist row's own id.
     *
     * A 404 for somebody else's row, matching how the rest of the platform answers a
     * record the caller has no business touching.
     */
    public function remove(User $user, int $itemId): void
    {
        $item = WishlistItem::whereKey($itemId)->where('user_id', $user->id)->first()
            ?? throw ApiException::notFound('That wishlist item does not exist.');

        $item->delete();
    }
}
