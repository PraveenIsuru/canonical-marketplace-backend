<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Attachment;
use App\Models\Product;
use App\Models\ProductView;

/**
 * Recording a product page view (EP-52).
 *
 * Small, but it holds the one rule that makes the analytics on the other side of this
 * table worth reading: **a view is attributed to a store only when that store actually
 * carries the product.** Without that check any anonymous caller could inflate a
 * competitor's numbers by posting a store id that has nothing to do with the page they
 * were looking at.
 *
 * The check cannot make the count unforgeable, because a public endpoint is
 * unauthenticated by definition and anyone may claim any real seller. What it does is
 * bound the damage to stores that genuinely carry the product, which is the most a
 * client side view counter can promise.
 */
final class ViewRecordingService
{
    /**
     * Records the view and returns the store it was attributed to, which is null when
     * no store context was supplied or when the supplied one does not carry the
     * product.
     *
     * **A store that does not carry the product is dropped rather than refused.** A
     * seller detaching between the page rendering and the view arriving is an ordinary
     * race, and answering 422 into a public page render would turn it into a visible
     * error for a visitor who did nothing wrong. The view itself still happened and is
     * still counted at product level.
     */
    public function record(Product $product, ?int $storeId): ?int
    {
        $attributedTo = $this->attributableStore($product, $storeId);

        ProductView::create([
            'product_id' => $product->id,
            'store_id' => $attributedTo,
            /*
             * Always null. EP-52 is a public route, and public routes resolve no
             * session, so there is no user to attribute a view to even when the
             * visitor happens to hold a token. The column stays for the sake of a
             * future authenticated path rather than for this one.
             */
            'user_id' => null,
            'viewed_at' => now(),
        ]);

        return $attributedTo;
    }

    private function attributableStore(Product $product, ?int $storeId): ?int
    {
        if ($storeId === null) {
            return null;
        }

        $carriesIt = Attachment::query()
            ->where('store_id', $storeId)
            ->where('product_id', $product->id)
            ->exists();

        return $carriesIt ? $storeId : null;
    }
}
