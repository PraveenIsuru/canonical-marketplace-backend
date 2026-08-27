<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Exceptions\ApiException;
use App\Models\CommunityPost;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * A product's discussion (EP-31, EP-32, EP-57).
 *
 * One discussion per **product**, shared by every variant of it. Owners of the 128GB
 * and the 256GB are talking about the same object, and splitting the thread by
 * combination would leave each one with too few participants to be worth reading.
 *
 * Reading is public and needs no account. Writing needs proven ownership **of this
 * product**, which is what makes the thread worth reading at all: everyone in it has
 * demonstrably held the thing.
 */
final class CommunityService
{
    public function __construct(private readonly VerificationService $verification) {}

    /**
     * Top level posts, newest first (EP-31).
     *
     * Cursor paginated rather than page numbered, per section 2 of the contract. A
     * discussion gains rows at the top while somebody is reading it, and page two of a
     * numbered paginator would show them a row they had already seen.
     *
     * Soft deleted posts are absent, which Eloquent handles, and so are the replies
     * beneath them, which it does not: see `replies()` below.
     *
     * @return CursorPaginator<int, CommunityPost>
     */
    public function posts(Product $product, int $perPage, ?string $cursor): CursorPaginator
    {
        return CommunityPost::query()
            ->where('product_id', $product->id)
            ->topLevel()
            ->with('author')
            // Counted rather than loaded. A list needs to know whether a thread has
            // replies, not what they say.
            ->withCount('replies')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }

    /**
     * Replies to one post, oldest first (EP-57).
     *
     * Oldest first, unlike the parent list, because a reply thread is a conversation
     * and reading one backwards makes no sense.
     *
     * **Refuses when the parent is soft deleted.** Eloquent hides the parent
     * automatically but would happily serve its children, and a removed post whose
     * replies survived would leave half a conversation with its subject missing. The
     * parent lookup is what enforces "deleted posts are hidden along with their
     * replies".
     *
     * @return CursorPaginator<int, CommunityPost>
     */
    public function replies(Product $product, int $postId, int $perPage, ?string $cursor): CursorPaginator
    {
        $parent = CommunityPost::query()
            ->where('product_id', $product->id)
            ->whereKey($postId)
            ->first()
            ?? throw ApiException::notFound('That post does not exist.');

        return CommunityPost::query()
            ->where('parent_id', $parent->id)
            ->with('author')
            ->withCount('replies')
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }

    /**
     * Writes a post or a reply (EP-32).
     *
     * Refused with **403 `not_verified`** unless this user has verified **this**
     * product. Verifying one product grants nothing on another, and the check is
     * scoped to both every time rather than to a role or a flag on the account.
     *
     * A `parent_id` naming a reply is refused: threads are one level deep, and a tree
     * on a product discussion is harder to read than a flat list.
     */
    public function post(User $user, Product $product, string $body, ?int $parentId): CommunityPost
    {
        if (! $this->verification->isVerifiedFor($user, $product)) {
            throw ApiException::notVerified();
        }

        $parent = null;

        if ($parentId !== null) {
            $parent = CommunityPost::query()
                ->where('product_id', $product->id)
                ->topLevel()
                ->whereKey($parentId)
                ->first()
                ?? throw ApiException::notFound('That post does not exist, or cannot be replied to.');
        }

        return CommunityPost::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'body' => $body,
        ]);
    }
}
