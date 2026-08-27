<?php

declare(strict_types=1);

namespace App\Services\Attach;

use App\Models\Product;

/**
 * What a completed wizard run produced.
 *
 * The two counts are reported separately and deliberately. Generated combinations
 * always equal or exceed the attachments created, because a seller carries some of
 * what the catalogue now describes and not necessarily all of it. That gap is expected
 * and is not an inconsistency to be reconciled anywhere.
 */
final readonly class WizardSubmitResult
{
    public function __construct(
        public Product $product,
        public int $versionNumber,
        public int $variantsGenerated,
        public int $attachmentsCreated,
        public bool $storeIsLive,
    ) {}
}
