<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ProductVersion;

/**
 * One version, paired with what changed to produce it (EP-46, EP-47).
 *
 * The changed fields are not a column. A version stores the whole record state rather
 * than a diff, so what changed is worked out by comparing it against the version
 * before, and that comparison belongs beside the version rather than inside a model
 * that has no idea another version exists.
 */
final readonly class VersionEntry
{
    /** @param  array<int, string>  $changedFields */
    public function __construct(
        public ProductVersion $version,
        public array $changedFields,
    ) {}
}
