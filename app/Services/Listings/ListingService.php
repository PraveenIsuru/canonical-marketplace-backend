<?php

declare(strict_types=1);

namespace App\Services\Listings;

use App\Exceptions\ApiException;
use App\Jobs\NotifyPriceDrop;
use App\Models\Attachment;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * A seller changing what they charge, and leaving a product entirely (EP-25, EP-26).
 *
 * The narrowest write surface a seller has. Two fields on their own attachment row and
 * nothing else: **no product, attribute, or variant field is reachable from here**, and
 * that is invariant 1 rather than an oversight. A seller who believes the record is
 * wrong opens a proposal; a seller who wants a different price uses this.
 *
 * Ownership lives in this service rather than in the controller, so a future caller
 * cannot reach the mutation without passing the same check.
 */
final class ListingService
{
    /**
     * Changes a price, an availability flag, or both.
     *
     * A **decrease** queues the price drop alert. An increase queues nothing: a buyer
     * asked to be told when something got cheaper, and telling them it got dearer would
     * be answering a question nobody asked.
     *
     * The comparison is made against the price as it was before this write, read inside
     * the transaction, so two concurrent updates cannot both decide they were the drop.
     *
     * @param  array{price_minor?: int, is_available?: bool}  $changes
     */
    public function update(Attachment $attachment, Store $caller, array $changes): Attachment
    {
        $this->assertOwnedBy($attachment, $caller);

        return DB::transaction(function () use ($attachment, $changes): Attachment {
            /** @var Attachment $locked */
            $locked = Attachment::whereKey($attachment->getKey())->lockForUpdate()->firstOrFail();

            $previousPrice = $locked->price_minor;

            $locked->fill($changes)->save();

            $newPrice = $locked->price_minor;
            $isDrop = array_key_exists('price_minor', $changes) && $newPrice < $previousPrice;

            if ($isDrop) {
                /*
                 * After the commit, so a rolled back price change cannot send an email
                 * telling buyers about a discount that never existed. An email is the
                 * one side effect of this platform that cannot be withdrawn.
                 */
                DB::afterCommit(fn () => NotifyPriceDrop::dispatch($locked->id, $newPrice));
            }

            return $locked;
        });
    }

    /**
     * Removes a seller from a variant.
     *
     * Deleted through the model, deliberately, rather than by a bulk query. The
     * `deleted` hook on Attachment is what recomputes the store's live flag, and a
     * bulk delete skips model events entirely: the store would keep selling to buyers
     * with nothing left on its shelves.
     *
     * **The product is untouched.** A canonical record is platform owned and outlives
     * every seller on it. Its page, its variants, and its version history all survive
     * the last seller leaving, and it simply reports no sellers.
     *
     * @return bool The store's live flag afterwards, which is false when this was the last listing.
     */
    public function detach(Attachment $attachment, Store $caller): bool
    {
        $this->assertOwnedBy($attachment, $caller);

        $attachment->delete();

        // Re-read rather than trusting the in memory model: the flag was changed by the
        // model event, on a separate instance of the store.
        return (bool) Store::whereKey($caller->getKey())->value('is_live');
    }

    /**
     * One seller, one set of listings.
     *
     * A 404 rather than a 403, matching how the platform treats every record a caller
     * has no business reading: confirming that attachment 901 exists but belongs to
     * somebody else tells a competitor something about their inventory.
     */
    private function assertOwnedBy(Attachment $attachment, Store $caller): void
    {
        if ($attachment->store_id !== $caller->id) {
            throw ApiException::notFound('That listing does not exist.');
        }
    }
}
