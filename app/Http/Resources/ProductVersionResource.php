<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Queries\VersionEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a product's version chain (EP-46), per section 11.11.
 *
 * **No administrator is ever named here.** A version caused by an administrator edit
 * says so with `is_admin_originated` and carries a null store. Attribution for a
 * change applied to a shared record is what an audit trail is for, but naming the
 * moderator who applied it serves no seller and gives a disgruntled one a target.
 *
 * **No proposal id either.** EP-29 answers 404 to any store that was neither the
 * proposer nor a frozen reviewer, which is most of the audience for this list, so the
 * id would be a link that mostly does not open.
 *
 * @property VersionEntry $resource
 */
class ProductVersionResource extends JsonResource
{
    public function __construct(VersionEntry $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $version = $this->resource->version;
        $store = $version->causedByStore;

        return [
            'version_number' => $version->version_number,
            'created_at' => $version->created_at->toIso8601String(),
            'is_admin_originated' => $version->is_admin_originated,

            /*
             * The store whose accepted proposal produced this version, and null on an
             * administrator edit. A rejected proposal wrote no version at all, so
             * nothing here can name a change that was argued for and refused.
             */
            'caused_by_store' => $store === null ? null : [
                'id' => $store->id,
                'name' => $store->name,
            ],

            'changed_fields' => $this->resource->changedFields,
        ];
    }
}
