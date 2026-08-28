<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Models\ProductAttribute;

/**
 * Widening an attribute's option list.
 *
 * **Additive only, and there is no method here that narrows one.** A combination
 * generated from an option is permanent, so removing the option would leave
 * combinations referring to a value the record no longer claims to have, and invariant
 * 2 means those combinations could never be cleaned up afterwards.
 *
 * Two callers, which is why this is a service rather than code inside either of them.
 * An approved proposal widens an attribute from what a seller typed, and an
 * administrator widens one directly at M11. Two implementations of "additive" would
 * eventually disagree about case, whitespace, or ordering, and the disagreement would
 * only surface as a duplicate option nobody could remove.
 */
final class AttributeService
{
    /**
     * Adds any options the definition does not already hold.
     *
     * Matching is case insensitive, so "black" against a record holding "Black" adds
     * nothing rather than producing two options that render identically and generate
     * two different combinations.
     *
     * Existing options are kept even when the caller did not list them. A seller
     * proposing "Black, Sand" against "Black, Grey" has told us about a version we did
     * not know about, not told us that Grey stopped existing.
     *
     * @param  array<int, string>  $options
     * @return bool whether anything was actually added
     */
    public function widen(ProductAttribute $definition, array $options): bool
    {
        $existing = $definition->options;
        $merged = $existing;

        foreach ($options as $option) {
            $option = trim($option);

            if ($option === '') {
                continue;
            }

            $alreadyPresent = array_filter(
                $merged,
                static fn (string $current): bool => mb_strtolower($current) === mb_strtolower($option),
            );

            if ($alreadyPresent === []) {
                $merged[] = $option;
            }
        }

        if (count($merged) === count($existing)) {
            return false;
        }

        $definition->options = array_values($merged);
        $definition->save();

        return true;
    }

    /**
     * Splits the comma separated list a proposal carries.
     *
     * A proposal records a change as a single string, because that is what the seller
     * typed and what a reviewer reads. An administrator sends a proper array instead,
     * so only the proposal path needs this.
     *
     * @return array<int, string>
     */
    public function parseOptionList(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $option): string => trim($option),
            explode(',', $value),
        ), static fn (string $option): bool => $option !== ''));
    }
}
