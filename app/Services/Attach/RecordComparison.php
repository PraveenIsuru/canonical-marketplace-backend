<?php

declare(strict_types=1);

namespace App\Services\Attach;

use App\Services\Ai\ConfirmationQuestion;

/**
 * Compares a seller's confirmation answers against what the record already holds.
 *
 * This decides the single most consequential branch in the platform. No differences
 * means the seller attaches immediately. Any difference means a proposal, peer review,
 * and the seller blocked from selling that product until it resolves.
 *
 * The comparison is deliberately **deterministic, not an AI call**. The submit step
 * already spends one provider call on scoring, and putting the branch itself behind a
 * second one would mean an identical submission could attach today and open a proposal
 * tomorrow. A rule that can be read is worth more here than a rule that is usually
 * cleverer.
 *
 * Its known limit, stated plainly: normalising case and spacing catches "192 " against
 * "192", but "two" against "2" reads as a difference and opens a proposal. That is the
 * safe direction to be wrong in. A spurious proposal is reviewed by people who know the
 * product and costs three days; a missed one silently corrupts a record every seller
 * shares.
 */
final class RecordComparison
{
    /**
     * The fields where the seller's answer differs from the record.
     *
     * Returns a map of attribute to the proposed value, which becomes the proposal's
     * `changes` document. An empty map is the attach path.
     *
     * @param  array<int, ConfirmationQuestion>  $questions
     * @param  array<string, string>  $answers  keyed by question id
     * @return array<string, array{from: string|null, to: string}>
     */
    public function differences(array $questions, array $answers): array
    {
        $changes = [];

        foreach ($questions as $question) {
            $answer = trim($answers[$question->id] ?? '');

            // Completeness is enforced before this runs, so a blank here would mean the
            // check was skipped. Ignoring it rather than proposing an empty value keeps
            // a bug upstream from erasing a field.
            if ($answer === '') {
                continue;
            }

            if ($this->matches($question->currentValue, $answer)) {
                continue;
            }

            $changes[$question->attribute] = [
                'from' => $question->currentValue,
                'to' => $answer,
            ];
        }

        return $changes;
    }

    /**
     * Whether two values say the same thing.
     *
     * Case, surrounding space, and repeated inner space are treated as noise, because
     * a seller typing "192 KHZ" has not disagreed with "192 kHz" about anything.
     *
     * A record value that is absent is not a match for any answer. The seller is
     * supplying something the record does not have, which is a change.
     */
    private function matches(?string $current, string $answer): bool
    {
        if ($current === null) {
            return false;
        }

        return $this->normalise($current) === $this->normalise($answer);
    }

    private function normalise(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_strtolower($collapsed);
    }
}
