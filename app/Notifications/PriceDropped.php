<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Attachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a buyer that something on their wishlist got cheaper.
 *
 * **Email only.** There is no in application notification surface in this platform, no
 * bell and no notification centre, so this is the entire mechanism. A buyer who does
 * not read the email does not find out, which is the trade the design accepts.
 *
 * Whether this sends at all is decided in NotifyPriceDrop, against the last price the
 * buyer was told about. By the time it reaches here, sending is the right thing to do.
 */
final class PriceDropped extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Attachment $attachment,
        private readonly int $newPriceMinor,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $variant = $this->attachment->variant;
        $product = $variant?->product;
        $store = $this->attachment->store;

        $name = $product->name ?? 'A product on your wishlist';

        /*
         * Divided by 100 for display only, here at the very edge. The integer is what
         * is stored, compared, and sent everywhere else in the platform.
         */
        $price = number_format($this->newPriceMinor / 100, 2);
        $currency = $this->attachment->currency;

        $message = (new MailMessage)
            ->subject("{$name} is cheaper at {$store?->name}")
            ->greeting('Something on your wishlist dropped in price')
            ->line("{$name} is now {$currency} {$price} at {$store?->name}.");

        // The exact combination, because a wishlist is saved per variant and the buyer
        // is watching one specific version rather than the product in general.
        $combination = $variant->attribute_values ?? [];

        if ($combination !== []) {
            $described = [];

            foreach ($combination as $attribute => $value) {
                $described[] = "{$attribute}: {$value}";
            }

            $message->line('The version you saved: '.implode(', ', $described).'.');
        }

        if ($product !== null) {
            $message->action('See who has it', url("/products/{$product->slug}"));
        }

        return $message->line(
            'Prices are set by each seller and can change again at any time. This is the '
            .'only alert you will get at this price.',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        // Not stored. There is no database notification channel in this platform, and
        // this exists only to satisfy the base class contract.
        return ['attachment_id' => $this->attachment->id];
    }
}
