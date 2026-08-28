<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells administrators that scheduled work has stopped having its effect.
 *
 * **Email only**, like every other notification in this platform. There is no bell and
 * no notification centre, so this is the entire mechanism.
 *
 * Deliberately **not** queued, which is the opposite of every other notification here.
 * The most likely reason this alert exists at all is that queued work is not running,
 * and an alert that queues behind the failure it is reporting would never arrive.
 *
 * The message says what is wrong and what it costs somebody, not what to type. An
 * administrator reading it at eight in the morning needs to know a seller has been
 * blocked since Tuesday; the command to run is in the runbook, and this is not the
 * runbook.
 */
final class MaintenanceHealthAlert extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $faults
     */
    public function __construct(private readonly array $faults) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Scheduled work on the marketplace needs attention')
            ->greeting('Something recurring has stopped working.')
            ->line(
                'The health check found the following. Each line describes a state the platform '
                .'should have corrected on its own and has not.'
            );

        foreach ($this->faults as $fault) {
            $message->line('- '.$fault);
        }

        return $message->line(
            'This check runs hourly. It will keep emailing until the underlying work runs again, '
            .'so a repeat means it is still unresolved rather than that it happened twice.'
        );
    }
}
