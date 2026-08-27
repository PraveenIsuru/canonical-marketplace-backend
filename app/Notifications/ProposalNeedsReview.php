<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a seller that a proposal on a product they carry needs their vote.
 *
 * **Email only.** There is no in application notification surface anywhere in this
 * platform, no bell, and no notification centre, so this is the entire mechanism by
 * which a reviewer finds out. If it does not send, nobody votes and the proposal
 * escalates for want of votes, which is a worse outcome than a noisy inbox.
 *
 * Queued, so a slow or unreachable mail server never fails the request that created the
 * proposal. The proposal exists either way and the review window has already started.
 *
 * The confidence score is not in this message and must never be. A reviewer told the
 * AI scored a submission highly would vote on that rather than on what they know about
 * the product, which is the one thing peer review is there to contribute.
 */
final class ProposalNeedsReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Proposal $proposal) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $product = $this->proposal->product;
        $closes = $this->proposal->review_closes_at;

        $message = (new MailMessage)
            ->subject("A change to {$product->name} needs your review")
            ->greeting('A product you sell has a proposed change')
            ->line(
                "Another seller who stocks {$product->name} has described it differently "
                .'from the catalogue, and the change is open for review by the sellers who carry it.',
            )
            ->line('You are being asked because your store carried this product when the proposal opened.');

        /*
         * The specific fields, so a reviewer can tell at a glance whether they know the
         * answer. Naming them in the email is what makes the difference between a vote
         * cast on knowledge and one cast to clear an inbox.
         */
        $fields = array_keys($this->proposal->changes);

        if ($fields !== []) {
            $message->line('What is being changed: '.implode(', ', $fields).'.');
        }

        return $message
            ->line("Voting closes on {$closes->toDayDateTimeString()} UTC, three days after it opened.")
            ->line(
                'If nobody votes, the proposal goes to an administrator rather than passing '
                .'or failing by default.',
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        // Not stored anywhere. There is no database notification channel in this
        // platform, and this exists only to satisfy the base class contract.
        return ['proposal_id' => $this->proposal->id];
    }
}
