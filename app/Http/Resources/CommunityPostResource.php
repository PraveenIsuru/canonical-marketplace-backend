<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CommunityPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One post in a product's discussion (EP-31, EP-57), per section 11.10.
 *
 * The author is a **display name and nothing else**. No user id, no email, and no
 * store: a user who happens to run a store posts here as a verified buyer like anyone
 * else, and naming their store would turn a discussion into advertising.
 *
 * There is deliberately no `is_verified` flag. An unverified author cannot post at all,
 * so the field would always be true, and a field that is always true is one that will
 * eventually be false by accident.
 *
 * @property CommunityPost $resource
 */
final class CommunityPostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $post = $this->resource;

        return [
            'id' => $post->id,
            'body' => $post->body,
            'author' => [
                // `??` already swallows the null access, so `?->` would be redundant.
                'name' => $post->author->name ?? 'A former member',
            ],
            // Always 0 on a reply: threads are one level deep and nothing nests further.
            'reply_count' => (int) ($post->replies_count ?? 0),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }
}
