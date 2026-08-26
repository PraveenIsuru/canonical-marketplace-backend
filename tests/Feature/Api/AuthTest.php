<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/**
 * M1 Accounts and roles. EP-01 to EP-07, EP-55, EP-56.
 *
 * The tests the build plan names for this milestone, plus envelope assertions, since
 * the frontend branches on these exact codes and field names.
 */

/*
|--------------------------------------------------------------------------
| EP-01 Register
|--------------------------------------------------------------------------
*/

it('registers an account and issues a token', function (): void {
    Notification::fake();

    $response = $this->postJson('/api/register', [
        'name' => 'Ada Perera',
        'email' => 'ada@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'is_admin', 'store']]])
        ->assertJsonPath('data.user.email', 'ada@example.com')
        // A new account is never an administrator and never holds a store.
        ->assertJsonPath('data.user.is_admin', false)
        ->assertJsonPath('data.user.store', null);

    $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'is_admin' => false]);
});

it('dispatches the verification email on registration', function (): void {
    Notification::fake();

    $this->postJson('/api/register', [
        'name' => 'Ada Perera',
        'email' => 'ada@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ])->assertCreated();

    Notification::assertSentTo(User::whereEmail('ada@example.com')->first(), VerifyEmail::class);
});

it('refuses a duplicate email with field errors', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/register', [
        'name' => 'Ada Perera',
        'email' => 'taken@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['code', 'message', 'errors' => ['email']]);
});

it('refuses registration when the password confirmation does not match', function (): void {
    $this->postJson('/api/register', [
        'name' => 'Ada Perera',
        'email' => 'ada@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'something-else-entirely',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['password']]);
});

it('never lets a registration payload grant itself administrator', function (): void {
    Notification::fake();

    $this->postJson('/api/register', [
        'name' => 'Ada Perera',
        'email' => 'ada@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
        'is_admin' => true,
    ])->assertCreated();

    expect(User::whereEmail('ada@example.com')->first()->is_admin)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| EP-02 Log in
|--------------------------------------------------------------------------
*/

it('logs in with correct credentials', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'password-that-is-long']);

    $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'password-that-is-long',
    ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']])
        ->assertJsonPath('data.user.id', $user->id);
});

it('does not reveal which credential was wrong', function (): void {
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'password-that-is-long']);

    $wrongPassword = $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'not-the-password',
    ])->assertStatus(422);

    $unknownEmail = $this->postJson('/api/login', [
        'email' => 'nobody@example.com',
        'password' => 'not-the-password',
    ])->assertStatus(422);

    // Identical bodies. A difference here is an account enumeration hole.
    expect($wrongPassword->json())->toBe($unknownEmail->json());
});

it('treats a soft deleted account as invalid credentials', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'password-that-is-long']);
    $user->delete();

    $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'password-that-is-long',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    // The row survives, which is what lets the account be reported as invalid rather
    // than as missing.
    $this->assertSoftDeleted('users', ['id' => $user->id]);
});

/*
|--------------------------------------------------------------------------
| EP-03 Log out
|--------------------------------------------------------------------------
*/

it('revokes only the current token on logout', function (): void {
    $user = User::factory()->create();
    $keep = $user->createToken('other-device')->plainTextToken;
    $current = $user->createToken('this-device')->plainTextToken;

    $this->withToken($current)->postJson('/api/logout')->assertNoContent();

    // Signing out on one device must not sign the user out everywhere.
    expect($user->tokens()->count())->toBe(1);
    $this->withToken($keep)->getJson('/api/user')->assertOk();
});

it('refuses logout without a token', function (): void {
    $this->postJson('/api/logout')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

/*
|--------------------------------------------------------------------------
| EP-04 The current user
|--------------------------------------------------------------------------
*/

it('returns the session user with a null store', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.is_admin', false)
        // Roles are derived. A null store is what makes this user not a seller.
        ->assertJsonPath('data.store', null);
});

it('reports an administrator through the flag', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => true]), 'sanctum')
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.is_admin', true);
});

it('never exposes the password hash or remember token', function (): void {
    $body = $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/user')
        ->getContent();

    expect($body)->not->toContain('password')
        ->and($body)->not->toContain('remember_token')
        ->and($body)->not->toContain('two_factor');
});

/*
|--------------------------------------------------------------------------
| EP-07 Saved location
|--------------------------------------------------------------------------
*/

it('saves a location and derives the postgis point in the same write', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/user/location', ['latitude' => 6.9271, 'longitude' => 79.8612])
        ->assertOk()
        ->assertJsonPath('data.latitude', 6.9271)
        ->assertJsonPath('data.longitude', 79.8612);

    // The decimal pair and the geography column must never disagree, so assert the
    // point was actually derived rather than left null.
    $row = DB::selectOne(
        'select ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng from users where id = ?',
        [$user->id],
    );

    expect(round((float) $row->lat, 4))->toBe(6.9271)
        ->and(round((float) $row->lng, 4))->toBe(79.8612);
});

it('refuses coordinates outside plausible bounds', function (float $lat, float $lng): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->patchJson('/api/user/location', ['latitude' => $lat, 'longitude' => $lng])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
})->with([
    'latitude too high' => [91.0, 79.8612],
    'latitude too low' => [-91.0, 79.8612],
    'longitude too high' => [6.9271, 181.0],
    'longitude too low' => [6.9271, -181.0],
]);

it('refuses a location update without a token', function (): void {
    $this->patchJson('/api/user/location', ['latitude' => 6.9271, 'longitude' => 79.8612])
        ->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| EP-05 and EP-06 Password reset
|--------------------------------------------------------------------------
*/

it('answers identically whether or not the address is registered', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'ada@example.com']);

    $known = $this->postJson('/api/password/forgot', ['email' => 'ada@example.com'])->assertOk();
    $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.com'])->assertOk();

    // Identical bodies, or this endpoint enumerates accounts.
    expect($known->json())->toBe($unknown->json());
});

it('sends a reset link pointing at the frontend', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/password/forgot', ['email' => 'ada@example.com'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        // The person needs a form to type into, and the API has no pages.
        return str_contains($url, (string) config('app.frontend_url'))
            && str_contains($url, '/reset-password?token=');
    });
});

it('resets a password with a valid token and revokes existing tokens', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->createToken('old-session');

    $token = Password::createToken($user);

    $this->postJson('/api/password/reset', [
        'token' => $token,
        'email' => 'ada@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    // A reset is often a response to a compromise, so old sessions must not survive.
    expect($user->tokens()->count())->toBe(0);

    $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'a-brand-new-password',
    ])->assertOk();
});

it('refuses an invalid or already used reset token', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = Password::createToken($user);

    $payload = [
        'token' => $token,
        'email' => 'ada@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ];

    $this->postJson('/api/password/reset', $payload)->assertOk();

    // Reusing the same token is reported exactly as an expired one would be.
    $this->postJson('/api/password/reset', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('refuses a reset token that has expired', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = Password::createToken($user);

    $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

    $this->postJson('/api/password/reset', [
        'token' => $token,
        'email' => 'ada@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

/*
|--------------------------------------------------------------------------
| EP-55 and EP-56 Email verification
|--------------------------------------------------------------------------
*/

it('resends the verification email', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/email/verification-notification')
        ->assertOk();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('does not resend for an already verified address', function (): void {
    Notification::fake();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/email/verification-notification')
        ->assertOk();

    Notification::assertNothingSent();
});

it('verifies an address from a signed link and redirects to the frontend', function (): void {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('api.verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->get($url)->assertRedirectContains((string) config('app.frontend_url'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('refuses a verification link whose signature is missing', function (): void {
    $user = User::factory()->unverified()->create();

    $this->getJson("/api/email/verify/{$user->id}/".sha1($user->email))
        ->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses a signed link replayed against a different account', function (): void {
    $target = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();

    // A correctly signed URL for one account, aimed at another's id.
    $url = URL::temporarySignedRoute('api.verification.verify', now()->addHour(), [
        'id' => $target->id,
        'hash' => sha1($other->getEmailForVerification()),
    ]);

    $this->getJson($url)->assertForbidden();

    expect($target->fresh()->hasVerifiedEmail())->toBeFalse();
});
