<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateLocationRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The session user and their saved location (EP-04, EP-07).
 */
final class UserController extends Controller
{
    /**
     * EP-04 The current user.
     *
     * Called by every authenticated page to resolve roles, so it stays small.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * EP-07 Update the saved location.
     *
     * The decimal columns and the PostGIS point are written in the same statement, so
     * the pair and the derived geography can never disagree. Two separate writes would
     * leave a window where they do, and the point is what proximity alerts are actually
     * calculated against.
     *
     * A user with no location receives no proximity alerts, which is correct rather
     * than a failure, so this endpoint is optional to call.
     */
    public function updateLocation(UpdateLocationRequest $request): JsonResponse
    {
        $latitude = (float) $request->validated('latitude');
        $longitude = (float) $request->validated('longitude');

        DB::update(
            'UPDATE users
                SET latitude = ?,
                    longitude = ?,
                    location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                    updated_at = NOW()
              WHERE id = ?',
            // ST_MakePoint takes longitude first. Reversing these is the classic
            // geospatial bug and puts every seller in the wrong hemisphere.
            [$latitude, $longitude, $longitude, $latitude, $request->user()->id],
        );

        return response()->json([
            'data' => new UserResource($request->user()->fresh()),
        ]);
    }
}
