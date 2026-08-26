<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Every platform route lives here. `web.php` serves nothing beyond the starter
| kit's own pages, which are out of scope.
|
| Routes are grouped by the four access levels from section 3 of
| development-docs/shared/api-contract.md:
|
|   Public   no middleware, no session resolution, works with no token
|   Auth     auth:sanctum
|   Seller   auth:sanctum + store
|   Admin    auth:sanctum + admin
|
| Endpoints land per milestone. See development-docs/backend-build-plan.md.
|
*/

/*
 * Health check. Deliberately public and dependency free, so it answers even when
 * Redis or the search engine are down. A health check that fails when a downstream
 * service fails cannot tell you which one broke.
 */
Route::get('/health', fn () => response()->json([
    'data' => [
        'status' => 'ok',
        'time' => now()->toIso8601String(),
    ],
]))->middleware('public');

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
| Anonymous readable. Must not change behaviour when a token happens to be present.
*/
Route::middleware(['public', 'throttle:catalogue'])->group(function (): void {
    // M2  EP-08, EP-09, EP-10, EP-11, EP-12, EP-13, EP-53
    // M9  EP-31, EP-57
    // M10 EP-52
});

Route::middleware(['public', 'throttle:search'])->group(function (): void {
    // M3  EP-14
});

/*
|--------------------------------------------------------------------------
| Authentication, public entry points
|--------------------------------------------------------------------------
| Credentials endpoints, each behind its own limiter.
*/
Route::post('/register', [AuthController::class, 'register'])
    ->middleware(['public', 'throttle:api-register']);

Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['public', 'throttle:api-login']);

Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])
    ->middleware(['public', 'throttle:api-password']);

Route::post('/password/reset', [PasswordResetController::class, 'reset'])
    ->middleware(['public', 'throttle:api-password']);

/*
 * EP-56. Opened from an email by a person, not called by the client, so it is signed
 * rather than authenticated and redirects into the frontend instead of returning JSON.
 */
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:api-password'])
    // Named api.* because Fortify already owns the bare `verification.verify` name
    // for the starter's web route. Two routes sharing a name silently break whichever
    // one loses, and route() would then build links to the wrong surface.
    ->name('api.verification.verify');

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    // M1
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [UserController::class, 'show']);

    Route::patch('/user/location', [UserController::class, 'updateLocation'])
        ->middleware('throttle:writes');

    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:api-password');

    // M5  EP-50
    // M8  EP-36, EP-37, EP-38
    // M9  EP-32, EP-33, EP-34, EP-35
});

/*
|--------------------------------------------------------------------------
| Seller
|--------------------------------------------------------------------------
| Refuses with 403 store_required when the caller holds no store.
*/
Route::middleware(['auth:sanctum', 'store'])->group(function (): void {
    // M3  EP-15
    // M4  EP-17, EP-18, EP-54
    // M5  EP-20, EP-23, EP-24, EP-48
    // M6  EP-19, EP-21, EP-22
    // M7  EP-27, EP-28, EP-29, EP-30
    // M8  EP-25, EP-26
    // M10 EP-39, EP-46, EP-47
});

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
| Refuses with 403 forbidden when is_admin is false.
*/
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function (): void {
    // M11 EP-40 to EP-45, EP-49, EP-58 to EP-61
});
