<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Collection;

/**
 * Generates the variant combinations of a product from its attribute options.
 *
 * Generation is additive and permanent. There is no method here that removes a
 * combination, and there is none anywhere else either, including for administrators. A
 * combination nobody carries simply holds no attachments and is shown as having no
 * sellers yet.
 *
 * This class is used by the wizard now and by administrator attribute edits at M11,
 * where a new option must add combinations without disturbing the ones that exist.
 * Writing it as generation from a full attribute set, rather than as wizard code, is
 * what makes that later reuse possible.
 */
final class VariantGenerationService
{
    /**
     * The cross product of every attribute option.
     *
     * With no attributes this returns a single empty combination, which is not a
     * special case bolted on. The cross product of no sets is one empty tuple, and that
     * is exactly the rule the platform wants: a product with no meaningful variation
     * carries one default variant rather than none.
     *
     * @param  array<int, array{name: string, options: array<int, string>}>  $attributes
     * @return array<int, array<string, string>>
     */
    public function combinations(array $attributes): array
    {
        $combinations = [[]];

        foreach ($attributes as $attribute) {
            $expanded = [];

            foreach ($combinations as $combination) {
                foreach ($attribute['options'] as $option) {
                    // Option order is preserved through the whole expansion, because it
                    // drives the order combinations are shown in and a seller who
                    // listed Red before Black expects to see them that way.
                    $expanded[] = $combination + [$attribute['name'] => $option];
                }
            }

            $combinations = $expanded;
        }

        return $combinations;
    }

    /**
     * Regenerates a product's combinations from whatever its attributes now say.
     *
     * The one entry point for "an attribute changed, catch the variants up", used by an
     * approved proposal and by an administrator edit. Both widen attributes and both
     * need exactly this afterwards, and two copies of it would eventually differ about
     * ordering, which decides how combinations are displayed.
     *
     * Additive, like everything else here. Every existing combination and every
     * existing attachment survives untouched: a shop carrying Black keeps carrying
     * Black when a new colour appears.
     *
     * @return Collection<int, Variant> every combination of the product, new and existing
     */
    public function regenerateFor(Product $product): Collection
    {
        $attributes = $product->productAttributes()->orderBy('position')->get()
            ->map(static fn ($attribute): array => [
                'name' => $attribute->name,
                'options' => $attribute->options,
            ])
            ->all();

        return $this->generateFor($product, $this->combinations($attributes));
    }

    /**
     * Writes the combinations that do not exist yet, and leaves the rest alone.
     *
     * Existing rows are matched by hash rather than by content, so a combination whose
     * keys arrive in a different order is recognised as the one already stored instead
     * of being written twice.
     *
     * @param  array<int, array<string, string>>  $combinations
     * @return Collection<int, Variant> every combination of the product, new and existing
     */
    public function generateFor(Product $product, array $combinations): Collection
    {
        $existing = $product->variants()->pluck('combination_hash')->all();
        $rows = [];

        foreach ($combinations as $combination) {
            $hash = Variant::hashCombination($combination);

            if (in_array($hash, $existing, true)) {
                continue;
            }

            $existing[] = $hash;

            $rows[] = [
                'product_id' => $product->id,
                'attribute_values' => json_encode((object) $combination),
                'combination_hash' => $hash,
                /*
                 * The default variant is the one with no attribute values, which
                 * happens only where the product defines no attributes at all. It is
                 * derived from the combination rather than passed in, so a caller
                 * cannot mark an ordinary combination as the default by mistake.
                 */
                'is_default' => $combination === [],
                'created_at' => now(),
            ];
        }

        if ($rows !== []) {
            // One insert rather than a save per combination. A product with three
            // attributes of four options each is sixty four rows, and sixty four
            // round trips inside the wizard transaction would hold it open needlessly.
            Variant::insert($rows);
        }

        return $product->variants()->get();
    }
}
