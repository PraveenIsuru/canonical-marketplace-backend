<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An administrator editing a record directly (EP-43), per section 11.12.
 *
 * Every field optional, at least one required, so an empty body is a validation failure
 * rather than a silent no op that writes a version recording nothing.
 *
 * **`slug` is not accepted.** It is the record's public address, every static page and
 * every inbound link is keyed by it, and a rename would break all of them for a
 * cosmetic gain. It is absent from these rules rather than validated and ignored, so a
 * client that sends one finds out.
 *
 * **`variants` is not accepted either**, and that absence is invariant 2. A combination
 * is generated from attribute options and is never written directly, never removed, and
 * never edited, by anybody. Accepting a variants array would be the shape a future
 * mistake needed.
 */
final class AdminEditProductRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category' => ['sometimes', 'string', 'max:100'],

            /*
             * Replaced wholesale when present, so a key left out is removed. A
             * specification is a free form fact with nothing generated from it, which is
             * exactly why it can be removed where an attribute option cannot.
             */
            'specifications' => ['sometimes', 'array'],

            /*
             * Additive, merged by name, and refused for a name the record does not
             * already define. That refusal is enforced in the service rather than here,
             * because it needs the product, and the message has to explain why adding a
             * dimension to a record with existing combinations is not possible.
             */
            'attributes' => ['sometimes', 'array'],
            'attributes.*.name' => ['required', 'string', 'max:100'],
            'attributes.*.options' => ['required', 'array', 'min:1'],
            'attributes.*.options.*' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $editable = ['name', 'description', 'category', 'specifications', 'attributes'];

                $sent = array_filter(
                    $editable,
                    fn (string $field): bool => $this->has($field),
                );

                if ($sent === []) {
                    $validator->errors()->add(
                        'name',
                        'Send at least one of name, description, category, specifications, or attributes.',
                    );
                }
            },
        ];
    }

    /**
     * @return array{name?: string, description?: string|null, category?: string, specifications?: array<string, mixed>, attributes?: array<int, array{name: string, options: array<int, string>}>}
     */
    public function changes(): array
    {
        /** @var array{name?: string, description?: string|null, category?: string, specifications?: array<string, mixed>, attributes?: array<int, array{name: string, options: array<int, string>}>} $validated */
        $validated = $this->validated();

        return $validated;
    }
}
