<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Jobs\RevalidateProductPage;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\Store;
use App\Models\User;

/**
 * Writes entries in a product's version chain, which is also its audit record.
 *
 * A version exists for an accepted proposal, an administrator edit, and the wizard
 * creating version 1. Nothing else may write one. In particular a rejected proposal
 * produces no version at all, which is why version creation is a deliberate call made
 * by a resolution path rather than something a product save triggers on its own.
 *
 * One class rather than three, because the snapshot has to be built the same way every
 * time. Two version writers would eventually disagree about what a snapshot contains,
 * and the disagreement would only surface when someone compared two versions written
 * years apart.
 */
final class ProductVersionService
{
    public function __construct(private readonly CatalogueCache $cache) {}

    /**
     * Records a new version and points the product at it.
     *
     * The pointer update is part of this call rather than left to the caller. A version
     * written without moving the pointer is invisible to every read path, and that is a
     * bug nothing would catch, since the version row would look perfectly correct.
     *
     * @param  Store|null  $causedByStore  the store whose action produced this version
     * @param  User|null  $causedByUser  the person who acted, whether seller or administrator
     */
    public function record(
        Product $product,
        ?Store $causedByStore = null,
        ?User $causedByUser = null,
        ?int $proposalId = null,
        bool $isAdminOriginated = false,
    ): ProductVersion {
        $version = ProductVersion::create([
            'product_id' => $product->id,
            'version_number' => $this->nextVersionNumber($product),
            'snapshot' => $this->snapshot($product),
            'proposal_id' => $proposalId,
            'caused_by_store_id' => $causedByStore?->id,
            'caused_by_user_id' => $causedByUser?->id,
            'is_admin_originated' => $isAdminOriginated,
        ]);

        $product->forceFill(['current_version_id' => $version->id])->save();

        /*
         * EP-51, and the only place it is dispatched from.
         *
         * A version is exactly the set of events that change what a product page says,
         * so dispatching here rather than from each resolution path is what makes
         * "revalidation fires on version creation and nothing else" a property of the
         * code rather than a rule four callers have to remember. A rejected proposal
         * writes no version, so it reaches nothing here to fire.
         *
         * `afterCommit` because every caller runs inside a transaction. A version that
         * rolls back must not have already told the client to rebuild the page around
         * it.
         */
        RevalidateProductPage::dispatch($product->slug)->afterCommit();

        /*
         * The cached catalogue reads for this product are now answering from a record
         * that has changed. Invalidated here for the same reason the dispatch is here:
         * one place, tied to the event that makes the cache wrong.
         */
        $this->cache->forgetProduct($product);

        return $version;
    }

    /**
     * The complete record state, not the fields that changed.
     *
     * Snapshots are larger than diffs, and that is the trade being made deliberately.
     * Reconstructing a version from diffs means replaying the chain from the beginning,
     * whereas a snapshot makes it a single row read. Since a proposal is accepted or
     * rejected as a whole, a version boundary already corresponds to a coherent state
     * of the whole record, so there is nothing a diff would express more truthfully.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Product $product): array
    {
        // Freshly loaded rather than trusting what the caller happened to have in
        // memory. A snapshot built from a stale relation would record a state the
        // product was never actually in.
        $attributes = $product->productAttributes()->orderBy('position')->get();
        $variants = $product->variants()->orderBy('id')->get();

        return [
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'category' => $product->category,
            'specifications' => (object) $product->specifications,
            'attributes' => $attributes->map(static fn ($attribute): array => [
                'name' => $attribute->name,
                'options' => $attribute->options,
                'position' => $attribute->position,
            ])->all(),
            'variants' => $variants->map(static fn ($variant): array => [
                'attribute_values' => (object) $variant->attribute_values,
                'combination_hash' => $variant->combination_hash,
                'is_default' => $variant->is_default,
            ])->all(),
        ];
    }

    /**
     * Version numbers count from one, per product.
     *
     * Read from the versions rather than from the current pointer, so a product whose
     * pointer is null still gets version 1 rather than starting over at a number
     * already used.
     */
    private function nextVersionNumber(Product $product): int
    {
        return (int) $product->versions()->max('version_number') + 1;
    }
}
