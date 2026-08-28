<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-52 Recording a product page view, per section 11.11.
 *
 * One optional field. `store_id` names the store the visitor arrived through, which is
 * the only store context a product page has, and is omitted for the far commoner case
 * of somebody reaching the product directly.
 *
 * There is deliberately no `exists:stores,id` rule. Whether the store carries this
 * product is what actually decides attribution, that check lives in the service, and a
 * store id that fails it is dropped rather than refused. Validating existence here
 * would produce a 422 for exactly the case the service is written to absorb.
 */
final class RecordProductViewRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function storeId(): ?int
    {
        $value = $this->validated('store_id');

        return $value === null ? null : (int) $value;
    }
}
