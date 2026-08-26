<?php

declare(strict_types=1);

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The error envelope is a contract, not a convention.
 *
 * Clients branch on `code`, so every one of these assertions is checking a string the
 * frontend has hard coded. A reworded code is a broken client.
 *
 * See development-docs/shared/api-contract.md, sections 1 and 7.
 */
it('answers the health check with a data envelope', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonStructure(['data' => ['status', 'time']])
        ->assertJsonPath('data.status', 'ok');
});

it('marks public routes with the public access level', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertHeader('X-Access-Level', 'public');
});

it('returns the standard envelope for an unknown api route', function (): void {
    $this->getJson('/api/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found')
        ->assertJsonStructure(['code', 'message']);
});

it('returns unauthenticated rather than redirecting when no token is present', function (): void {
    Route::middleware('auth:sanctum')->get('/api/_test/protected', fn () => response()->json(['data' => true]));

    $this->getJson('/api/_test/protected')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('returns store_required when a seller route is hit without a store', function (): void {
    Route::middleware(['auth:sanctum', 'store'])->get('/api/_test/seller', fn () => response()->json(['data' => true]));

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/_test/seller')
        ->assertForbidden()
        ->assertJsonPath('code', 'store_required');
});

it('returns forbidden when an admin route is hit by a non administrator', function (): void {
    Route::middleware(['auth:sanctum', 'admin'])->get('/api/_test/admin', fn () => response()->json(['data' => true]));

    $this->actingAs(User::factory()->create(['is_admin' => false]), 'sanctum')
        ->getJson('/api/_test/admin')
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('includes field errors on a validation failure', function (): void {
    Route::post('/api/_test/validate', function (Request $request) {
        $request->validate(['email' => 'required|email']);
    });

    $this->postJson('/api/_test/validate', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['code', 'message', 'errors' => ['email']]);
});

it('puts the queued job id at the top level for ai unavailability', function (): void {
    Route::get('/api/_test/ai', function (): never {
        throw new AiUnavailableException('job-abc-123');
    });

    $this->getJson('/api/_test/ai')
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_unavailable')
        // Top level, not inside data. The client polls this to recover the flow.
        ->assertJsonPath('queued_job_id', 'job-abc-123');
});

it('maps every domain exception to its registered code', function (string $method, int $status, string $code): void {
    Route::get("/api/_test/domain/{$code}", function () use ($method): never {
        throw ApiException::$method();
    });

    $this->getJson("/api/_test/domain/{$code}")
        ->assertStatus($status)
        ->assertJsonPath('code', $code);
})->with([
    ['storeRequired', 403, 'store_required'],
    ['storeExists', 409, 'store_exists'],
    ['proposalPending', 409, 'proposal_pending'],
    ['alreadyAttached', 409, 'already_attached'],
    ['confirmationIncomplete', 422, 'confirmation_incomplete'],
    ['matchRequired', 422, 'match_required'],
    ['alreadyVoted', 409, 'already_voted'],
    ['reviewClosed', 409, 'review_closed'],
    ['notEligibleToVote', 403, 'not_eligible_to_vote'],
    ['notAttached', 403, 'not_attached'],
    ['notVerified', 403, 'not_verified'],
    ['attemptsExhausted', 403, 'attempts_exhausted'],
    ['unsupportedMediaType', 422, 'unsupported_media_type'],
    ['fileTooLarge', 422, 'file_too_large'],
    ['imageLimitReached', 422, 'image_limit_reached'],
]);
