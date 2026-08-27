<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\WishlistItem;
use App\Notifications\NearbyAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Tells buyers that somebody near them has started stocking a variant they saved.
 *
 * The counterpart to the price drop alert. That one fires when a listing gets cheaper;
 * this one fires when a listing appears at all, which is the case a buyer who saved a
 * combination nobody carried is actually waiting for.
 *
 * **Distance is the whole point of this alert**, so a buyer with no coordinates gets
 * nothing from it. That is the documented cost of declining the location prompt rather
 * than a fault: the platform cannot tell somebody a shop is nearby without knowing
 * where they are. It does not fall back to notifying everyone, which would turn a
 * useful alert into a marketing email.
 */
final class NotifyNearbyAvailability implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $attachmentId) {}

    public function handle(): void
    {
        $attachment = Attachment::with(['variant.product', 'store'])->find($this->attachmentId);

        if ($attachment === null || ! $attachment->is_available) {
            return;
        }

        $store = $attachment->store;

        // A store with no coordinates cannot be near anyone. Geocoding is allowed to
        // fail at registration, so this is a real state rather than a defensive check.
        if ($store === null || $store->latitude === null || $store->longitude === null) {
            return;
        }

        $radiusMetres = (float) config('alerts.nearby_radius_km', 25) * 1000;

        /*
         * Distance decided in PostGIS rather than in PHP, matching how the seller list
         * does it. ST_DWithin on a geography column uses metres and can use the spatial
         * index, where pulling every wishlist row and measuring each in PHP could not.
         */
        $items = WishlistItem::query()
            ->where('wishlist_items.variant_id', $attachment->variant_id)
            ->join('users', 'users.id', '=', 'wishlist_items.user_id')
            ->whereNotNull('users.latitude')
            ->whereNotNull('users.longitude')
            ->whereRaw(
                'ST_DWithin(ST_SetSRID(ST_MakePoint(users.longitude, users.latitude), 4326)::geography, '
                .'ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                [$store->longitude, $store->latitude, $radiusMetres],
            )
            ->select('wishlist_items.*')
            ->with('user')
            ->get();

        foreach ($items as $item) {
            $user = $item->user;

            // The seller is not told about their own wishlist. They know what they
            // just listed.
            if ($user === null || $user->id === $store->user_id) {
                continue;
            }

            Notification::send($user, new NearbyAvailability($attachment));
        }
    }
}
