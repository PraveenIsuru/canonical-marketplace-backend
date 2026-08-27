<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Attachment;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * One product a store carries, with every version of it the store lists.
 *
 * Grouped this way because a seller thinks in products: "the ceramic jug, in two
 * sizes", not two unrelated rows that happen to share a name.
 *
 * An object rather than an array shape so the grouping has a name and the types
 * survive being passed around. The resource that renders it reads `product` and
 * `variants` rather than guessing at string keys.
 */
final readonly class StoreListing
{
    /**
     * @param  Collection<int, Attachment>  $variants
     */
    public function __construct(
        public Product $product,
        public Collection $variants,
    ) {}
}
