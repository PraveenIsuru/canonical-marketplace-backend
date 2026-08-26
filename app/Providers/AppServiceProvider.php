<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureEmailedLinks();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Point emailed links at the right surface.
     *
     * Both of these links are opened by a person in a browser, not called by the
     * client, so the framework defaults are wrong for a separated frontend and
     * backend. Left alone, they would land on the starter kit's unused Inertia pages.
     */
    protected function configureEmailedLinks(): void
    {
        /*
         * Verification hits the API, which marks the address verified and then
         * redirects into the frontend. The API has to be in the path because the
         * signature must be checked somewhere that can write to the database.
         */
        VerifyEmail::createUrlUsing(fn (User $notifiable): string => URL::temporarySignedRoute(
            'api.verification.verify',
            now()->addMinutes(config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        ));

        /*
         * Password reset goes straight to the frontend, because the person needs a
         * form to type a new password into and the API has no pages. The token is
         * carried in the query string and posted back to EP-06.
         */
        ResetPassword::createUrlUsing(fn (CanResetPassword $notifiable, string $token): string => sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim((string) config('app.frontend_url'), '/'),
            $token,
            urlencode($notifiable->getEmailForPasswordReset()),
        ));
    }
}
