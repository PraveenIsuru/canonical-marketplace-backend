<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\CommunityPost;

/**
 * Removing a post from the discussion (EP-44).
 *
 * The only moderation action in the platform, and the only thing that can remove a
 * post at all. There is no user ban, no post edit, and no restore, and none of those
 * should be built: they are on the build plan's list of things deliberately absent.
 *
 * Image deletion is not here. It lives beside the upload in ProductImageService, which
 * already owns the decision about which disk images are stored on, and splitting that
 * decision across two classes is how a moderated file ends up orphaned on the wrong
 * volume.
 */
final class AdminModerationService
{
    /**
     * EP-44 Removes a post.
     *
     * **Soft deleted, never destroyed.** The row survives and every read path already
     * hides it: Eloquent drops the post itself, and the reply endpoint refuses when the
     * parent is gone, which is what makes "hidden along with their replies" true rather
     * than half true.
     *
     * **No tombstone anywhere.** A removed post does not appear as a placeholder and its
     * thread does not survive it, per section 11.10. Inventing one would advertise a
     * moderation history that this platform deliberately does not publish.
     *
     * @return int how many replies went with it, which is zero when the post removed is
     *             itself a reply
     */
    public function deletePost(CommunityPost $post): int
    {
        /*
         * Counted before the delete rather than after. Once the parent is soft deleted
         * the replies are hidden from every read path, so asking afterwards would be
         * asking about rows the platform has already stopped acknowledging.
         */
        $repliesHidden = $post->parent_id === null
            ? $post->replies()->count()
            : 0;

        $post->delete();

        return $repliesHidden;
    }
}
