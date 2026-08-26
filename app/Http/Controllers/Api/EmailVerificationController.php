<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Email verification (EP-55, EP-56).
 */
final class EmailVerificationController extends Controller
{
    /**
     * EP-55 Resend the verification email.
     *
     * Registration succeeds even when the email fails to send, so this is the path
     * back for a user whose message never arrived.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'data' => ['message' => 'This address is already verified.'],
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'data' => ['message' => 'A new verification email is on its way.'],
        ]);
    }

    /**
     * EP-56 The signed link target.
     *
     * Reached from the email rather than from the client, so it redirects into the
     * frontend instead of returning JSON. The signature is validated by the `signed`
     * middleware on the route.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        // Guards against a signed link being replayed against a different account.
        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            throw ApiException::forbidden('This verification link is not valid.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->away(
            rtrim(config('app.frontend_url'), '/').'/account?verified=1'
        );
    }
}
