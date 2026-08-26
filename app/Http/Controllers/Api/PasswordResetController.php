<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password reset (EP-05, EP-06).
 */
final class PasswordResetController extends Controller
{
    /**
     * EP-05 Request a reset link.
     *
     * Returns the identical response whether or not the address is registered, so the
     * endpoint cannot be used to enumerate accounts. The broker's own return value is
     * deliberately ignored for that reason.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->safe()->only('email'));

        return response()->json([
            'data' => [
                'message' => 'If that address has an account, a reset link is on its way.',
            ],
        ]);
    }

    /**
     * EP-06 Set a new password from a token.
     *
     * An expired token and an already used token are reported the same way, because
     * the distinction tells the sender something about a token they should not hold.
     *
     * Every existing access token is revoked on success. A password reset is often a
     * response to a compromise, and leaving old sessions alive would defeat it.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->safe()->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'token' => __('This reset link is no longer valid. Request a new one.'),
            ]);
        }

        return response()->json([
            'data' => ['message' => 'Your password has been reset.'],
        ]);
    }
}
