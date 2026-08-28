<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * One version with its full snapshot (EP-47), per section 11.11.
 *
 * The list entry plus the record state as it stood at that version. A snapshot rather
 * than a diff, so reading one version costs a single row instead of replaying the
 * chain from the beginning.
 *
 * There is no rollback endpoint and none is planned. History is read only, and an
 * administrator who wants an old value back edits forward, which writes a further
 * version and leaves the record of how it got there intact.
 *
 * Extends the list entry rather than repeating it, so a field added there cannot be
 * forgotten here. That matters most for the two fields that are deliberately absent:
 * no administrator identity and no proposal id.
 */
final class ProductVersionSnapshotResource extends ProductVersionResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            'snapshot' => $this->normalise($this->resource->version->snapshot),
        ];
    }

    /**
     * Restores the empty maps that a round trip through JSON turns into arrays.
     *
     * A product with no specifications, and a default variant with no attribute values
     * at all, both hold an empty map. PHP decodes that back as an empty array and would
     * re-encode it as `[]`, which is a different type to the client than the `{}` every
     * other resource in the platform emits for the same field.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function normalise(array $snapshot): array
    {
        $snapshot['specifications'] = (object) ($snapshot['specifications'] ?? []);

        $variants = $snapshot['variants'] ?? [];

        if (is_array($variants)) {
            $snapshot['variants'] = array_map(static function (mixed $variant): mixed {
                if (is_array($variant)) {
                    $variant['attribute_values'] = (object) ($variant['attribute_values'] ?? []);
                }

                return $variant;
            }, $variants);
        }

        return $snapshot;
    }
}
