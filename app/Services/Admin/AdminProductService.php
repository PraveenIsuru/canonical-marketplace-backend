<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Jobs\IndexProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalogue\AttributeService;
use App\Services\Catalogue\ProductVersionService;
use App\Services\Catalogue\VariantGenerationService;
use Illuminate\Support\Facades\DB;

/**
 * Editing a canonical record directly (EP-43).
 *
 * The one path into product data that is not a proposal, and it exists because some
 * corrections have nobody to propose them: a product no seller carries has no reviewer
 * set, and a plainly wrong category blocks buyers from finding a record that nobody
 * with a stake in it is watching.
 *
 * It does not weaken invariant 1. **No seller reaches this**, at any access level. The
 * seller path into product data is a proposal and remains the only one.
 */
final class AdminProductService
{
    public function __construct(
        private readonly ProductVersionService $versions,
        private readonly VariantGenerationService $variants,
        private readonly AttributeService $attributes,
    ) {}

    /**
     * Applies an administrator's edit and records the version it caused.
     *
     * One transaction covering the record, the attributes, the combinations they now
     * make possible, and the version. A half applied edit that widened an attribute
     * without generating its combinations would leave options a buyer can select and no
     * combination behind them.
     *
     * **A pending proposal on the same product does not block this, and this does not
     * disturb the proposal.** The proposal still applies its own values if it is later
     * approved. Making an administrator wait three days for a peer review to conclude
     * before fixing an obvious error would be the wrong trade, and a proposal is a
     * claim about the record rather than a lock on it.
     *
     * @param  array{name?: string, description?: string|null, category?: string, specifications?: array<string, mixed>, attributes?: array<int, array{name: string, options: array<int, string>}>}  $changes
     */
    public function edit(Product $product, User $administrator, array $changes): Product
    {
        return DB::transaction(function () use ($product, $administrator, $changes): Product {
            $touchedAttributes = $this->applyAttributes($product, $changes['attributes'] ?? []);

            foreach (['name', 'description', 'category'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $product->{$field} = $changes[$field];
                }
            }

            /*
             * Replaced wholesale rather than merged, so a key left out of the payload is
             * removed. A specification is a free form fact with nothing generated from
             * it, which is exactly why it can be removed where an attribute option
             * cannot.
             */
            if (array_key_exists('specifications', $changes)) {
                $product->specifications = $changes['specifications'];
            }

            $product->save();

            if ($touchedAttributes) {
                $this->variants->regenerateFor($product->refresh());
            }

            /*
             * An administrator originated version: the flag is set, no store caused it,
             * and the acting administrator is recorded on the row. They are named to
             * nobody, per section 11.11, which is why the identity lives here and not in
             * any resource.
             */
            $this->versions->record(
                $product->refresh(),
                causedByUser: $administrator,
                isAdminOriginated: true,
            );

            DB::afterCommit(fn () => IndexProduct::dispatch($product->id));

            return $product->refresh();
        });
    }

    /**
     * Widens the named attributes, and refuses to invent one.
     *
     * **Additive only**, exactly as an approved proposal is. Sending a shorter option
     * list than the record holds adds nothing and removes nothing.
     *
     * **An attribute the record does not already define is refused.** Adding a new
     * dimension to a record that already has combinations would leave every one of them
     * with no value for it, permanently, since invariant 2 means nothing can remove a
     * combination afterwards. The wizard is where a product's attribute set is decided,
     * and it is decided once.
     *
     * @param  array<int, array{name: string, options: array<int, string>}>  $submitted
     * @return bool whether any option list actually changed
     */
    private function applyAttributes(Product $product, array $submitted): bool
    {
        if ($submitted === []) {
            return false;
        }

        $definitions = $product->productAttributes()->get()->keyBy(
            static fn ($attribute): string => mb_strtolower($attribute->name),
        );

        $widened = false;

        foreach ($submitted as $attribute) {
            $definition = $definitions->get(mb_strtolower($attribute['name']));

            if ($definition === null) {
                throw new ApiException(422, 'validation_failed', 'The given data was invalid.', [
                    'attributes' => [
                        "This product does not define an attribute called \"{$attribute['name']}\". "
                        .'Options can be added to an existing attribute, but a new attribute would leave '
                        .'every combination already generated without a value for it.',
                    ],
                ]);
            }

            if ($this->attributes->widen($definition, $attribute['options'])) {
                $widened = true;
            }
        }

        return $widened;
    }
}
