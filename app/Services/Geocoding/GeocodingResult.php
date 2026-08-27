<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * The outcome of a geocoding attempt.
 *
 * Failure is a value, not an exception. An address failing to resolve is an expected
 * path that routes the seller into manual pin placement, not an error a caller should
 * have to catch. Modelling it this way is what keeps the failure from being handled by
 * try/catch scattered through registration code.
 *
 * Provider unreachable and provider returned no match are the same outcome. The seller
 * does the same thing either way, so the distinction would only ever be noise.
 */
final readonly class GeocodingResult
{
    private function __construct(
        public bool $succeeded,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {}

    public static function found(float $latitude, float $longitude): self
    {
        return new self(true, $latitude, $longitude);
    }

    public static function unresolved(): self
    {
        return new self(false);
    }

    public function failed(): bool
    {
        return ! $this->succeeded;
    }

    /**
     * Coordinates outside plausible bounds are treated as a failure.
     *
     * A provider that answers with nonsense is no more useful than one that does not
     * answer, and letting it through would put a store somewhere it is not.
     */
    public static function fromProvider(?float $latitude, ?float $longitude): self
    {
        if ($latitude === null || $longitude === null) {
            return self::unresolved();
        }

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return self::unresolved();
        }

        return self::found($latitude, $longitude);
    }
}
