<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * EP-24 Submit the wizard and create the canonical record.
 *
 * The most consequential validation in the milestone. Everything this request lets
 * through is written permanently, because the platform has no product deletion path
 * and no way to remove a generated variant combination. A mistake accepted here stays
 * in the catalogue.
 */
final class SubmitWizardRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'uuid'],

            // The answers themselves are checked against the session's questions in the
            // controller, since only the stored session knows what was asked.
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'string', 'max:2000'],

            'name' => ['required', 'string', 'min:2', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', 'string', 'max:100'],

            // Absent or empty means the product has no meaningful variation, which is
            // valid and produces a single default variant.
            'attributes' => ['nullable', 'array', 'max:5'],
            'attributes.*.name' => ['required', 'string', 'max:100'],
            'attributes.*.options' => ['required', 'array', 'min:1', 'max:20'],
            'attributes.*.options.*' => ['required', 'string', 'max:255'],

            /*
             * At least one. A seller reaches the wizard in order to sell something, and
             * a run carrying nothing would create a permanent canonical record while
             * leaving the store dark, which is not an outcome the flow describes.
             */
            'carried_variants' => ['required', 'array', 'min:1'],
            'carried_variants.*.attribute_values' => ['present', 'array'],
            'carried_variants.*.attribute_values.*' => ['required', 'string', 'max:255'],

            // Integer in the smallest currency unit, and above zero. The API never
            // accepts a decimal price, so a float here is a rejection rather than a
            // value to round.
            'carried_variants.*.price_minor' => ['required', 'integer', 'min:1'],
            'carried_variants.*.currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    /**
     * The checks that need to see more than one field at a time.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertAttributeNamesAreDistinct($validator);
            $this->assertOptionsAreDistinct($validator);
            $this->assertCarriedVariantsAreDistinct($validator);
        });
    }

    /**
     * Two attributes cannot share a name.
     *
     * A combination is a map keyed by attribute name, so a duplicate name would silently
     * overwrite one attribute with the other and generate half the combinations the
     * seller defined, with no indication anything was lost.
     */
    private function assertAttributeNamesAreDistinct(Validator $validator): void
    {
        $names = array_map(
            static fn ($attribute): string => mb_strtolower(trim((string) ($attribute['name'] ?? ''))),
            (array) $this->input('attributes', []),
        );

        if (count($names) !== count(array_unique($names))) {
            $validator->errors()->add('attributes', 'Each attribute must have a different name.');
        }
    }

    /** A repeated option would generate the same combination twice. */
    private function assertOptionsAreDistinct(Validator $validator): void
    {
        foreach ((array) $this->input('attributes', []) as $index => $attribute) {
            $options = array_map(
                static fn ($option): string => mb_strtolower(trim((string) $option)),
                (array) ($attribute['options'] ?? []),
            );

            if (count($options) !== count(array_unique($options))) {
                $validator->errors()->add("attributes.{$index}.options", 'Each option must be different.');
            }
        }
    }

    /**
     * The same combination cannot be listed at two prices.
     *
     * An attachment is unique per store and variant, so the second one would fail
     * against the database constraint and roll the whole submission back. Catching it
     * here turns an opaque failure into a message naming the entry.
     */
    private function assertCarriedVariantsAreDistinct(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('carried_variants', []) as $index => $entry) {
            $values = (array) ($entry['attribute_values'] ?? []);
            ksort($values);
            $key = (string) json_encode($values);

            if (in_array($key, $seen, true)) {
                $validator->errors()->add(
                    "carried_variants.{$index}.attribute_values",
                    'This combination is listed more than once.',
                );
            }

            $seen[] = $key;
        }
    }
}
