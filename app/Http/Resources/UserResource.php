<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The session user (EP-04).
 *
 * This is how the frontend derives roles. There is no roles array, because a user is
 * a seller if a store exists and an administrator if the flag is set.
 *
 * The store object stays minimal on purpose. The settings form uses EP-54, which
 * returns the full record. Fattening this payload would slow the call that every
 * authenticated page makes.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'is_admin' => $this->is_admin,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,

            /*
             * Null until a store exists. The stores table lands at M4, so this is
             * hard coded null rather than guessed at, and the contract already
             * describes it as nullable.
             */
            'store' => null,
        ];
    }
}
