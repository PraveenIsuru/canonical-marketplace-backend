<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateCommunityPostRequest;
use App\Http\Resources\CommunityPostResource;
use App\Jobs\SummariseCommunity;
use App\Models\Product;
use App\Services\Community\CommunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A product's discussion (EP-31, EP-32, EP-57).
 *
 * Reading is public and resolves no session, like the rest of the catalogue. Writing
 * needs proven ownership of **this** product, which is what makes the thread worth
 * reading: everybody in it has demonstrably held the thing being discussed.
 *
 * Thin. Verification, the reply depth rule, and the soft delete behaviour all live in
 * CommunityService.
 */
final class CommunityController extends Controller
{
    public function __construct(private readonly CommunityService $community) {}

    /** EP-31 Top level posts, newest first. Public. */
    public function index(Request $request, Product $product): AnonymousResourceCollection
    {
        $posts = $this->community->posts(
            $product,
            $this->perPage($request),
            $request->query('cursor') === null ? null : (string) $request->query('cursor'),
        );

        return CommunityPostResource::collection($posts);
    }

    /** EP-57 Replies to one post, oldest first. Public. */
    public function replies(Request $request, Product $product, int $post): AnonymousResourceCollection
    {
        $replies = $this->community->replies(
            $product,
            $post,
            $this->perPage($request),
            $request->query('cursor') === null ? null : (string) $request->query('cursor'),
        );

        return CommunityPostResource::collection($replies);
    }

    /**
     * EP-32 Write a post or a reply.
     *
     * Refused with 403 `not_verified` unless the caller has verified this product.
     */
    public function store(CreateCommunityPostRequest $request, Product $product): JsonResponse
    {
        $parentId = $request->validated('parent_id');

        $post = $this->community->post(
            $request->user(),
            $product,
            (string) $request->validated('body'),
            $parentId === null ? null : (int) $parentId,
        );

        /*
         * The summary is regenerated in the background rather than on a schedule, so it
         * follows the discussion rather than the clock. Queued and never awaited: a
         * provider outage must not fail the post, which is already written, and a
         * product whose summary is a few posts behind is a perfectly good state.
         */
        SummariseCommunity::dispatch($product->id)->afterCommit();

        return (new CommunityPostResource($post->load('author')))
            ->response()
            ->setStatusCode(201);
    }

    /** Capped at 100 by the contract, section 2. */
    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->integer('per_page', 20)));
    }
}
