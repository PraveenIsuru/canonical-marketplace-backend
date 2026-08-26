<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Which path served a search response.
 *
 * The backend is the single authority on this. The frontend reads the value and never
 * attempts its own fallback, because two layers deciding independently would
 * eventually disagree about what a visitor was shown.
 *
 * An enum rather than a string so a typo cannot reach the response body, where the
 * client branches on it.
 */
enum SearchMode: string
{
    case Ai = 'ai';
    case Keyword = 'keyword';
}
