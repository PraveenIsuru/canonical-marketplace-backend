<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * EP-25 A seller changing their own listing, per section 11.9.
 *
 * Two fields, both optional, at least one required. Optional so a seller can change a
 * price without restating availability, and at least one so an empty body is a
 * validation failure rather than a silent no op that reports success.
 *
 * **Nothing about the product is accepted here.** No name, no specification, no
 * attribute value, and no variant. Those belong to the canonical record, which no
 * seller writes to, and the only route into them is a proposal. A field added to these
 * rules would be a hole in invariant 1.
 *
 * There is no `currency` either: a store's currency is fixed at registration and is
 * not a per listing decision.
 */
final class UpdateListingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * An integer in the smallest currency unit, and strictly positive.
             *
             * `min:1` rather than `min:0`: a free listing is not a listing, and a
             * negative price is not a discount. `integer` rather than `numeric`
             * because a decimal price never crosses this boundary in either direction.
             */
            'price_minor' => ['sometimes', 'integer', 'min:1'],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function messages(): array
    {
        return [
            'price_minor.min' => 'A price must be greater than zero.',
            'price_minor.integer' => 'A price must be a whole number in the smallest currency unit.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('price_minor') && ! $this->has('is_available')) {
                    $validator->errors()->add(
                        'price_minor',
                        'Send a price, an availability flag, or both.',
                    );
                }
            },
        ];
    }

    /** @return array{price_minor?: int, is_available?: bool} */
    public function changes(): array
    {
        /** @var array{price_minor?: int, is_available?: bool} $validated */
        $validated = $this->validated();

        return $validated;
    }
}
