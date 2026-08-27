<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Attachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a buyer that a shop near them has started stocking something they saved.
 *
 * **Email only**, like every notification in this platform.
 *
 * Whether the store counts as near is decided in NotifyNearbyAvailability, in PostGIS,
 * against the buyer's own coordinates. By the time it reaches here the answer is yes.
 */
final class NearbyAvailability extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Attachment $attachment) {}

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

        // Divided by 100 for display only, at the very edge of the system.
        $price = number_format($this->attachment->price_minor / 100, 2);

        $message = (new MailMessage)
            ->subject("{$name} is now available near you")
            ->greeting('Something on your wishlist is in stock nearby')
            ->line(
                "{$store?->name} in {$store?->city} has started stocking {$name} at "
                ."{$this->attachment->currency} {$price}.",
            );

        $combination = $variant->attribute_values ?? [];

        if ($combination !== []) {
            $described = [];

            foreach ($combination as $attribute => $value) {
                $described[] = "{$attribute}: {$value}";
            }

            $message->line('The version you saved: '.implode(', ', $described).'.');
        }

        if ($product !== null) {
            $message->action('See the product', url("/products/{$product->slug}"));
        }

        return $message->line(
            'You are getting this because the shop is within the distance we treat as '
            .'nearby for the location on your account.',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        // Not stored. There is no database notification channel in this platform.
        return ['attachment_id' => $this->attachment->id];
    }
}
