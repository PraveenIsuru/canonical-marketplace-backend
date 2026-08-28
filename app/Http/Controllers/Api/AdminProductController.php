<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminEditProductRequest;
use App\Http\Resources\AdminProductDetailResource;
use App\Http\Resources\AdminProductSummaryResource;
use App\Models\Product;
use App\Queries\AdminProductsQuery;
use App\Services\Admin\AdminProductService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The administrator catalogue (EP-60, EP-61, EP-43).
 *
 * **Keyed by id, unlike every public product route, which is keyed by slug.** That is
 * deliberate rather than inconsistent. A slug is a public address that describes a
 * record and could in principle be wrong about it; an id is the record. An
 * administrator correcting a name should be operating on the row, not on a string
 * derived from the thing they are about to change.
 *
 * Thin. The edit and everything it causes live in AdminProductService.
 */
final class AdminProductController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AdminProductsQuery $products,
        private readonly AdminProductService $edits,
    ) {}

    /** EP-60 Every product, newest first, with `?q=` and `?category=`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return AdminProductSummaryResource::collection(
            $this->products->all(
                $this->perPage($request),
                $request->string('q')->toString(),
                $request->string('category')->toString(),
            ),
        );
    }

    /** EP-61 One product in full, every combination included. */
    public function show(int $product): AdminProductDetailResource
    {
        return new AdminProductDetailResource($this->find($product));
    }

    /**
     * EP-43 Edits a record directly.
     *
     * The one path into product data that is not a proposal, and it does not weaken
     * invariant 1: no seller reaches it, at any access level.
     *
     * Writes an **administrator originated version**, and the acting administrator is
     * recorded on the row and named to nobody. A pending proposal on the same product
     * neither blocks this nor is disturbed by it.
     */
    public function update(AdminEditProductRequest $request, int $product): AdminProductDetailResource
    {
        $edited = $this->edits->edit(
            $this->find($product),
            $request->user(),
            $request->changes(),
        );

        // Re-read through the query so the response carries the same counts EP-61 does,
        // rather than a partially loaded model that happens to be in memory.
        return new AdminProductDetailResource($this->find($edited->id));
    }

    private function find(int $id): Product
    {
        return $this->products->find($id)
            ?? throw ApiException::notFound('That product does not exist.');
    }

    /** Capped at 100 by the contract, section 2. */
    private function perPage(Request $request): int
    {
        return min(self::MAX_PER_PAGE, max(1, (int) $request->integer('per_page', 20)));
    }
}
