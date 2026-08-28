<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-39 The date range a seller is asking about, per section 11.11.
 *
 * Both bounds are optional and default to the last thirty days ending today, so the
 * screen can load without deciding anything. Days are UTC days throughout, matching
 * section 5.
 *
 * The 366 day ceiling is not arbitrary. `product_views` is the fastest growing table
 * in the system and the daily series returns one entry per day in the range, so an
 * unbounded range is both an unbounded query and an unbounded response.
 */
final class StoreAnalyticsRequest extends FormRequest
{
    private const DEFAULT_DAYS = 30;

    private const MAX_DAYS = 366;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, mixed> */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end of the range cannot be before its start.',
            'from.date_format' => 'Dates are YYYY-MM-DD.',
            'to.date_format' => 'Dates are YYYY-MM-DD.',
        ];
    }

    public function to(): CarbonImmutable
    {
        $value = $this->validated('to');

        return is_string($value)
            ? CarbonImmutable::createFromFormat('Y-m-d', $value, 'UTC')->startOfDay()
            : CarbonImmutable::now('UTC')->startOfDay();
    }

    /**
     * The start of the range.
     *
     * A range longer than the ceiling is pulled forward to it rather than refused. The
     * seller asked for a period, and answering the most recent year of it is more
     * useful than a validation error about a limit they had no way to know.
     */
    public function from(): CarbonImmutable
    {
        $value = $this->validated('from');
        $to = $this->to();

        $from = is_string($value)
            ? CarbonImmutable::createFromFormat('Y-m-d', $value, 'UTC')->startOfDay()
            : $to->subDays(self::DEFAULT_DAYS - 1);

        $earliest = $to->subDays(self::MAX_DAYS - 1);

        return $from->lessThan($earliest) ? $earliest : $from;
    }
}
