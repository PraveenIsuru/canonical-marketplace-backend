<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One product matching thinks the seller may be describing (EP-20).
 *
 * Just enough to recognise a product by: the seller is being asked "is this the one?",
 * not being shown a catalogue listing. Price and seller count are absent because they
 * are irrelevant to that question and would invite the seller to compare rather than
 * identify.
 *
 * `match_score` is the AI's confidence in this comparison, and it is not the confidence
 * score the contract forbids exposing. That one is written to a proposal and drives the
 * peer review resolution matrix. This one describes a search result and decides nothing.
 *
 * @mixin Product
 */
final class MatchCandidateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            // Null where the record has no images yet, which is common for a product
            // created through the wizard by a seller who uploaded none.
            'primary_image_url' => $this->images->first()?->url(),
            'match_score' => (float) $this->match_score,
        ];
    }
}
