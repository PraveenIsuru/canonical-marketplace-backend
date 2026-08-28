<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Who may look at the queue, and who hears about it when it goes wrong.
 *
 * Horizon's dashboard shows job payloads, and a payload here can carry a proposal id,
 * a store id, and a product slug. That is not catalogue data, so the dashboard is
 * treated as an administrator surface rather than an operations convenience, and the
 * gate below is the only thing standing in front of it.
 *
 * The dashboard is a session authenticated web route, not a token authenticated API
 * route. It is the one part of this repository a person visits in a browser, which is
 * why it resolves a session when nothing else outside the Auth group does.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        /*
         * Long waits and failures are emailed, because invariant 10 says notifications
         * are email only and an operations channel is still a notification.
         *
         * The address is configuration rather than a constant, so a deployment that has
         * nobody to tell simply leaves it unset and Horizon stays quiet instead of
         * failing to send.
         */
        $address = config('horizon.notification_email');

        if (is_string($address) && $address !== '') {
            Horizon::routeMailNotificationsTo($address);
        }
    }

    /**
     * Administrators only, in every environment including local.
     *
     * `is_admin` is the same flag the admin middleware checks, so there is one answer
     * to "who is an administrator" rather than two that can drift apart. Seeding a
     * second list of email addresses here, which is what the published stub does,
     * would be exactly that second answer.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn (?User $user = null): bool => $user?->is_admin === true);
    }
}
