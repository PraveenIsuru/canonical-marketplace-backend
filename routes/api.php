<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AiJobController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AttachController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\ConfirmationController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductImageController;
use App\Http\Controllers\Api\ProductVersionController;
use App\Http\Controllers\Api\ProductViewController;
use App\Http\Controllers\Api\ProposalController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SellerStoreController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Controllers\Api\WishlistController;
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
    // M2
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::get('/products/{product}/variants', [ProductController::class, 'variants']);
    Route::get('/products/{product}/sellers', [ProductController::class, 'sellers']);
    Route::get('/products/{product}/summary', [ProductController::class, 'summary']);
    /*
     * Constrained to a numeric id so it cannot swallow /stores/mine, which is
     * registered later in this file and would otherwise never be reached: route model
     * binding would try to resolve a store with the id "mine".
     */
    Route::get('/stores/{store}', [StoreController::class, 'show'])->whereNumber('store');

    /*
     * M9 The discussion, EP-31 and EP-57.
     *
     * Public and session free, like the rest of the catalogue. Reading needs no account:
     * the value of a verified discussion is to the person deciding what to buy, and
     * putting it behind a login would hide it from exactly them.
     *
     * Writing is a different matter entirely and lives in the Auth group.
     */
    Route::get('/products/{product}/community/posts', [CommunityController::class, 'index']);
    Route::get('/products/{product}/community/posts/{post}/replies', [CommunityController::class, 'replies'])
        ->whereNumber('post');

    /*
     * M10 EP-52. Recording that somebody looked at a product.
     *
     * A write on a public route, which is unusual enough to say why: the thing being
     * recorded is an anonymous page view, so requiring a token would count only the
     * minority of visitors who happen to hold one and would make the numbers say
     * something other than what a seller reads them as saying.
     *
     * Behind the catalogue limiter rather than a limiter of its own, because it is one
     * call per product page render and belongs to the same traffic.
     */
    Route::post('/products/{product}/views', [ProductViewController::class, 'store']);
});

Route::middleware(['public', 'throttle:search'])->group(function (): void {
    // M3. Never returns ai_unavailable; it falls back to keyword results instead.
    Route::get('/search', [SearchController::class, 'buyer']);
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

    // M4. EP-16 is Auth rather than Seller on purpose: the caller has no store yet, so
    // the seller check would refuse the request that creates one.
    Route::post('/stores', [SellerStoreController::class, 'store'])
        ->middleware('throttle:writes');

    /*
     * M5 EP-50. Auth rather than Seller, because buyer facing AI work queues here too
     * from M9 onward. The job's own owner check is what actually protects it.
     */
    Route::get('/jobs/{job}', [AiJobController::class, 'show']);

    /*
     * M8 The wishlist, EP-36 to EP-38.
     *
     * Auth rather than Seller: a user who runs a store keeps a wishlist like anyone
     * else. One account holds both roles and there is no mode switch in this platform.
     */
    Route::get('/wishlist', [WishlistController::class, 'index']);

    Route::post('/wishlist', [WishlistController::class, 'store'])
        ->middleware('throttle:writes');

    Route::delete('/wishlist/{item}', [WishlistController::class, 'destroy'])
        ->whereNumber('item')
        ->middleware('throttle:writes');

    /*
     * M9 Verification and posting, EP-32 to EP-35.
     *
     * Auth rather than Seller: this is a buyer capability, and a user who runs a store
     * uses it as a verified buyer like anyone else.
     *
     * Verification is scoped to a product every time. Verifying one grants nothing on
     * another, so every route here is bound to a product and none of them is a
     * capability the account carries around.
     */
    Route::post('/products/{product}/community/posts', [CommunityController::class, 'store'])
        ->middleware('throttle:writes');

    Route::get('/products/{product}/verification', [VerificationController::class, 'show']);

    Route::post('/products/{product}/verification/start', [VerificationController::class, 'start'])
        ->middleware('throttle:verification');

    /*
     * Behind the `verification` limiter, which is 5 per minute per actor. The real
     * ceiling is the five attempts per product enforced in the service; this only stops
     * somebody hammering the provider with photographs faster than a person could take
     * them.
     */
    Route::post('/products/{product}/verification/submit', [VerificationController::class, 'submit'])
        ->middleware('throttle:verification');

    /*
     * M10 Version history, EP-46 and EP-47.
     *
     * Auth rather than Seller, unlike every other seller facing read in this file. The
     * contract grants these to a seller attached to the product **or** an
     * administrator, and the seller middleware would refuse an administrator holding no
     * store of their own before the request reached the controller.
     *
     * The real check is in VersionHistoryService and is re-read on every request, so a
     * seller who detaches loses the history on their next call rather than at their
     * next sign in.
     */
    Route::get('/products/{product}/versions', [ProductVersionController::class, 'index']);

    Route::get('/products/{product}/versions/{version}', [ProductVersionController::class, 'show'])
        ->whereNumber('version');
});

/*
|--------------------------------------------------------------------------
| Seller
|--------------------------------------------------------------------------
| Refuses with 403 store_required when the caller holds no store.
*/
Route::middleware(['auth:sanctum', 'store'])->group(function (): void {
    // M3. Unlike buyer search, this one queues and returns 503 on provider failure,
    // because a degraded result here could admit a duplicate canonical record.
    Route::get('/seller/catalogue-search', [SearchController::class, 'sellerCatalogue'])
        ->middleware('throttle:search');

    // M4
    Route::get('/stores/mine', [SellerStoreController::class, 'show']);

    Route::patch('/stores/mine', [SellerStoreController::class, 'update'])
        ->middleware('throttle:writes');

    Route::post('/stores/mine/pin', [SellerStoreController::class, 'placePin'])
        ->middleware('throttle:writes');

    /*
     * M5 The attachment flow.
     *
     * Behind the `attach` limiter, which is 20 per hour. Each of these costs a provider
     * call, and the wizard submit writes a permanent canonical record, so the quota is
     * deliberately far tighter than the general write limit.
     */
    Route::middleware('throttle:attach')->group(function (): void {
        Route::post('/attach/match', [AttachController::class, 'match']);
        Route::post('/attach/wizard/start', [AttachController::class, 'wizardStart']);
        Route::post('/attach/wizard/submit', [AttachController::class, 'wizardSubmit']);
    });

    /*
     * M5 EP-48. Bound by slug, like every other product route, because that is the
     * product's route key and public URLs are keyed by it.
     */
    Route::post('/products/{product}/images', [ProductImageController::class, 'store'])
        ->middleware('throttle:writes');

    /*
     * M6 EP-19. What this store carries, and what it is blocked on.
     *
     * Registered before the `/stores/{store}` public route can matter, and reachable
     * because that one is constrained to a numeric id.
     */
    Route::get('/stores/mine/listings', [SellerStoreController::class, 'listings']);

    /*
     * M6 The confirmation flow. Behind the same `attach` limiter as matching and the
     * wizard: each costs a provider call, and submit writes a proposal that blocks a
     * seller for three days.
     */
    Route::middleware('throttle:attach')->group(function (): void {
        Route::post('/attach/confirm/start', [ConfirmationController::class, 'start']);
        Route::post('/attach/confirm/submit', [ConfirmationController::class, 'submit']);
    });
    /*
     * M7 Peer review.
     *
     * The reads are ordinary seller reads. The vote is behind the write limiter: it is
     * cheap to make but can resolve a proposal, which writes a version, changes a
     * shared record, and unblocks a seller.
     *
     * Both static paths are declared before `/proposals/{proposal}`, so "mine" and
     * "to-review" are never mistaken for proposal ids.
     */
    Route::get('/proposals/mine', [ProposalController::class, 'mine']);
    Route::get('/proposals/to-review', [ProposalController::class, 'toReview']);
    Route::get('/proposals/{proposal}', [ProposalController::class, 'show'])
        ->whereNumber('proposal');

    Route::post('/proposals/{proposal}/vote', [ProposalController::class, 'vote'])
        ->whereNumber('proposal')
        ->middleware('throttle:writes');

    /*
     * M8 Listing management, EP-25 and EP-26.
     *
     * The entirety of a seller's write access to the catalogue: their own price and
     * their own availability. Nothing here reaches a product, an attribute, or a
     * variant, which is invariant 1 and the reason a proposal exists.
     *
     * Behind the write limiter rather than the attach limiter. These cost no provider
     * call, and a seller repricing a shelf of stock should not burn an attach quota.
     */
    Route::patch('/attachments/{attachment}', [ListingController::class, 'update'])
        ->whereNumber('attachment')
        ->middleware('throttle:writes');

    Route::delete('/attachments/{attachment}', [ListingController::class, 'destroy'])
        ->whereNumber('attachment')
        ->middleware('throttle:writes');

    /*
     * M10 EP-39. This store's view counts, over a date range.
     *
     * No store id parameter, here or anywhere on this route. The caller's own store is
     * the only store this can answer about.
     */
    Route::get('/stores/mine/analytics', [AnalyticsController::class, 'show']);
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
