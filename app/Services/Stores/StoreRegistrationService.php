<?php

declare(strict_types=1);

namespace App\Services\Stores;

use App\Contracts\GeocodingProvider;
use App\Exceptions\ApiException;
use App\Models\Store;
use App\Models\User;

/**
 * Store registration, editing, and manual pin placement.
 *
 * The whole of seller onboarding's decision making lives here. The controllers only
 * translate between HTTP and these methods.
 */
final class StoreRegistrationService
{
    public const SOURCE_PROVIDER = 'locationiq';

    public const SOURCE_MANUAL = 'manual_pin';

    public function __construct(private readonly GeocodingProvider $geocoder) {}

    /**
     * Creates the caller's store.
     *
     * A failed geocode does **not** fail the request. The store is created with null
     * coordinates and the caller is told `geocoding_failed`, which routes the seller
     * into manual pin placement. Refusing here would throw away details the seller
     * correctly submitted and turn a defined fallback into an error they did not cause.
     *
     * `is_live` stays false regardless. A store becomes visible to buyers only once it
     * holds at least one attachment, which cannot happen during onboarding.
     *
     * @param  array<string, mixed>  $details
     */
    public function create(User $user, array $details): StoreWriteResult
    {
        // Checked before anything is written, so a duplicate attempt cannot leave a
        // half created row behind.
        if ($user->store()->exists()) {
            throw ApiException::storeExists();
        }

        $result = $this->geocoder->geocode((string) $details['address_line'], (string) $details['city']);

        $store = new Store;
        $store->fill($details);
        $store->user_id = $user->id;

        if ($result->succeeded) {
            $store->latitude = $result->latitude;
            $store->longitude = $result->longitude;
            $store->geocode_source = self::SOURCE_PROVIDER;
        }

        // Never set from a payload. Visibility is derived from attachment count.
        $store->is_live = false;
        $store->save();

        return new StoreWriteResult($store->refresh(), geocodingFailed: $result->failed());
    }

    /**
     * Updates the editable details.
     *
     * Re-geocodes only when the address or the city actually changed. Re-running it on
     * a phone number edit would spend a provider call to answer a question nobody asked,
     * and could replace good coordinates with a worse match.
     *
     * On a failed re-geocode the **previous coordinates are kept**. The seller had a
     * working location a moment ago, and discarding it would make an edit to an
     * unrelated field silently remove the store from every proximity sorted list.
     *
     * Editing details never changes `is_live`.
     *
     * @param  array<string, mixed>  $details
     */
    public function update(Store $store, array $details): StoreWriteResult
    {
        $addressChanged =
            (array_key_exists('address_line', $details) && $details['address_line'] !== $store->address_line)
            || (array_key_exists('city', $details) && $details['city'] !== $store->city);

        $store->fill($details);

        if (! $addressChanged) {
            $store->save();

            return new StoreWriteResult($store->refresh(), geocodingFailed: false);
        }

        $result = $this->geocoder->geocode((string) $store->address_line, (string) $store->city);

        if ($result->succeeded) {
            $store->latitude = $result->latitude;
            $store->longitude = $result->longitude;
            $store->geocode_source = self::SOURCE_PROVIDER;
        }

        $store->save();

        return new StoreWriteResult($store->refresh(), geocodingFailed: $result->failed());
    }

    /**
     * Places the store by hand.
     *
     * Records the source as manual placement rather than provider derived. The
     * distinction is what makes later data quality review possible: a hand placed pin
     * is a seller's best guess, and a provider result is not.
     */
    public function placePin(Store $store, float $latitude, float $longitude): Store
    {
        $store->latitude = $latitude;
        $store->longitude = $longitude;
        $store->geocode_source = self::SOURCE_MANUAL;
        $store->save();

        return $store->refresh();
    }
}
