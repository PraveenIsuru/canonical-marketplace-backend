<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Models\Proposal;
use App\Queries\StoreListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * EP-19, what a seller carries and what they are blocked on.
 *
 * Named for the owner, like OwnStoreResource. SellerListingResource is a different
 * thing entirely: the public seller list on a product page, which buyers read.
 *
 * Two arrays rather than one, and the second is the point. A product with a proposal
 * under review has **no attachment row at all**, so a screen built from `listings`
 * alone would show nothing and leave the seller wondering where their submission went.
 * `blocked` is what lets it say "waiting on the other sellers" instead.
 *
 * No confidence score appears on a blocked entry. The proposing seller does not get to
 * see how the AI scored them any more than a reviewer does.
 */
final class OwnListingsResource extends JsonResource
{
    /**
     * @param  array{listings: Collection<int, StoreListing>, blocked: Collection<int, Proposal>}  $result
     */
    public function __construct(private readonly array $result)
    {
        parent::__construct($result);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'listings' => $this->result['listings']->map(static fn (StoreListing $listing): array => [
                'product' => [
                    'id' => $listing->product->id,
                    'slug' => $listing->product->slug,
                    'name' => $listing->product->name,
                    'primary_image_url' => $listing->product->images->first()?->url(),
                ],
                'variants' => $listing->variants->map(static fn (Attachment $attachment): array => [
                    'attachment_id' => $attachment->id,
                    'variant_id' => $attachment->variant_id,
                    'attribute_values' => (object) $attachment->variant->attribute_values,
                    // Integer in the smallest currency unit, as everywhere else.
                    'price_minor' => $attachment->price_minor,
                    'currency' => $attachment->currency,
                    'is_available' => $attachment->is_available,
                ])->values(),
            ])->values(),

            'blocked' => $this->result['blocked']->map(static fn (Proposal $proposal): array => [
                'proposal_id' => $proposal->id,
                'status' => $proposal->status,
                'review_opens_at' => $proposal->review_opens_at->toIso8601String(),
                'review_closes_at' => $proposal->review_closes_at->toIso8601String(),
                /*
                 * Which fields are under review, so the screen can say what is being
                 * argued about rather than only that something is. The values are the
                 * seller's own submission and the record they came from, neither of
                 * which is secret from the person who wrote them.
                 */
                'changed_fields' => array_keys($proposal->changes),
                'product' => [
                    'id' => $proposal->product->id,
                    'slug' => $proposal->product->slug,
                    'name' => $proposal->product->name,
                ],
            ])->values(),
        ];
    }
}
