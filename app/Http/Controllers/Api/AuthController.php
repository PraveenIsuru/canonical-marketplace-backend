<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Registration, login, and logout for the platform API (EP-01, EP-02, EP-03).
 *
 * Separate from the starter kit's Fortify web auth, which stays unused. This is the
 * token surface the Next.js client talks to.
 */
final class AuthController extends Controller
{
    /** The token name recorded against every issued personal access token. */
    private const TOKEN_NAME = 'platform';

    /**
     * EP-01 Register.
     *
     * Email dispatch failure must not fail registration. The account exists either
     * way, and the resend endpoint (EP-55) covers the gap. A user who cannot receive
     * mail still has an account they can log into.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            Log::warning('Verification email could not be dispatched at registration.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data' => [
                'token' => $user->createToken(self::TOKEN_NAME)->plainTextToken,
                'user' => new UserResource($user),
            ],
        ], 201);
    }

    /**
     * EP-02 Log in.
     *
     * A soft deleted account is reported as invalid credentials rather than as a
     * deleted account. The model's SoftDeletes scope excludes trashed rows from the
     * lookup, so this falls out of the query rather than needing a separate branch,
     * and there is no code path that could accidentally disclose the difference.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            // One message, attached to a field that does not say which half was wrong.
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        return response()->json([
            'data' => [
                'token' => $user->createToken(self::TOKEN_NAME)->plainTextToken,
                'user' => new UserResource($user),
            ],
        ]);
    }

    /**
     * EP-03 Log out.
     *
     * Revokes the current token only, not every token the user holds. Signing out on
     * a phone should not sign them out on a laptop.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        // An already expired token completes without error rather than returning 401,
        // because the caller's intent, to end up signed out, is already satisfied.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(null, 204);
    }
}
