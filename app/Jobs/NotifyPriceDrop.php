<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\WishlistItem;
use App\Notifications\PriceDropped;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Tells buyers who wishlisted a variant that it just got cheaper.
 *
 * Queued because it fans out across every buyer watching the variant, and a seller
 * editing a price should not wait on that. The price change is committed either way.
 *
 * **Only a decrease reaches this job.** The caller decides that, not this class: a
 * buyer asked to hear when something got cheaper, and an alert on a rise answers a
 * question nobody asked.
 *
 * The suppression rule is the interesting part, and it lives here.
 */
final class NotifyPriceDrop implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $attachmentId,
        public readonly int $newPriceMinor,
    ) {}

    public function handle(): void
    {
        /*
         * Re-read rather than passed as a model. By the time this runs the price may
         * have moved again, and an email quoting a price nobody can buy at is worse
         * than a late one.
         */
        $attachment = Attachment::with(['variant.product', 'store'])->find($this->attachmentId);

        if ($attachment === null || $attachment->price_minor !== $this->newPriceMinor) {
            return;
        }

        // An unavailable listing is not an offer. Telling somebody the price fell on
        // something out of stock sends them to an empty shelf.
        if (! $attachment->is_available) {
            return;
        }

        $items = WishlistItem::query()
            ->where('variant_id', $attachment->variant_id)
            ->with('user')
            ->get();

        foreach ($items as $item) {
            /*
             * The suppression rule.
             *
             * A buyer already told about this price, or a lower one, hears nothing.
             * Without this a seller oscillating a price around a threshold would send
             * an email on every downswing, and the buyer would learn to ignore all of
             * them, which costs more than the alert is worth.
             *
             * Null means never notified, so the first drop always sends.
             */
            if ($item->last_notified_price_minor !== null
                && $item->last_notified_price_minor <= $this->newPriceMinor) {
                continue;
            }

            $user = $item->user;

            if ($user === null) {
                continue;
            }

            Notification::send($user, new PriceDropped($attachment, $this->newPriceMinor));

            /*
             * Stamped after sending rather than before. Sending twice because the job
             * retried is a nuisance; stamping a price the buyer was never told about
             * would silence every future alert down to that figure, which is a fault
             * they could never diagnose.
             */
            $item->forceFill(['last_notified_price_minor' => $this->newPriceMinor])->save();
        }
    }
}
