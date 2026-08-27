<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Contracts\AiProvider;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\User;
use App\Models\VerificationAttempt;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\OwnershipAssessment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Proving that somebody physically has a product (EP-33, EP-34, EP-35).
 *
 * The platform issues a code, the buyer writes it on paper and photographs it beside
 * the product, and the provider judges both halves. The code is what makes the
 * photograph evidence of **present possession** rather than a picture found online,
 * which is the whole difference between this and an unverified comment thread.
 *
 * Two rules run through everything here:
 *
 *  - **Verification is per user per product.** Verifying a phone grants nothing about a
 *    laptop. Every query in this class is scoped to both.
 *  - **The photograph is deleted the moment verification concludes**, on a pass and on
 *    a failure alike, and no method returns a path. That is invariant 7 and section 6
 *    of the contract, and it is enforced here rather than trusted to callers.
 */
final class VerificationService
{
    public function __construct(private readonly AiProvider $ai) {}

    /**
     * What the composer renders from (EP-33).
     *
     * Returns enough to answer every state without the client inferring anything:
     * signed in but unverified, verified, out of attempts, mid attempt with a code
     * already issued, or waiting on a queued provider call.
     *
     * @return array<string, mixed>
     */
    public function status(User $user, Product $product): array
    {
        $attempts = VerificationAttempt::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->orderBy('attempt_number')
            ->get();

        $isVerified = $attempts->contains(
            fn (VerificationAttempt $a): bool => $a->outcome === VerificationAttempt::OUTCOME_PASSED,
        );

        // Only concluded attempts are spent. A started attempt whose photograph never
        // arrived costs the buyer nothing, so walking away is free.
        $used = $attempts->filter(fn (VerificationAttempt $a): bool => $a->isConcluded())->count();
        $remaining = max(0, VerificationAttempt::MAX_ATTEMPTS - $used);

        $pending = $attempts->last(
            fn (VerificationAttempt $a): bool => $a->outcome === VerificationAttempt::OUTCOME_PENDING,
        );

        return [
            'is_verified' => $isVerified,
            'attempts_used' => $used,
            'attempts_remaining' => $remaining,
            'can_attempt' => ! $isVerified && $remaining > 0,
            'latest_outcome' => $attempts->last()?->outcome,
            /*
             * Handed back so a buyer who closed the page can see the code again rather
             * than starting over. Starting over would be harmless, but appearing to
             * cost an attempt would make them hesitate.
             */
            'pending_code' => $pending?->generated_code,
        ];
    }

    /** True when this user has verified **this** product. Never any other. */
    public function isVerifiedFor(User $user, Product $product): bool
    {
        return VerificationAttempt::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where('outcome', VerificationAttempt::OUTCOME_PASSED)
            ->exists();
    }

    /**
     * Issues a code and opens an attempt (EP-34).
     *
     * **Starting does not consume an attempt.** The row is written as `pending`, and
     * only a concluded submission counts against the ceiling. A buyer who starts, finds
     * they cannot photograph the product today, and comes back tomorrow has lost
     * nothing.
     *
     * Re-starting while one is already open returns the same code rather than issuing
     * another, so a buyer refreshing the page does not end up with a code that no
     * longer matches the one they have already written down.
     */
    public function start(User $user, Product $product): VerificationAttempt
    {
        $this->assertCanAttempt($user, $product);

        $existing = VerificationAttempt::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where('outcome', VerificationAttempt::OUTCOME_PENDING)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $used = $this->concludedCount($user, $product);

        return VerificationAttempt::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'generated_code' => $this->issueCode(),
            'attempt_number' => $used + 1,
            'outcome' => VerificationAttempt::OUTCOME_PENDING,
        ]);
    }

    /**
     * Judges a submitted photograph (EP-35).
     *
     * The photograph is read into memory, handed to the provider as bytes, and
     * **deleted before this method returns**, whichever way the judgement went. There
     * is no path in the return value and no path in any response built from it.
     *
     * On provider failure the photograph must survive until the queued job can judge
     * it, so the caller is handed the stored path to put in the job payload. That is
     * the one moment a path leaves this class, it goes to a queued job rather than to a
     * response, and `CompleteVerification` deletes it on the same terms.
     *
     * @throws VerificationQueued when the provider is down. The photograph is left in
     *                            place for the queued job, which deletes it.
     */
    public function submit(User $user, Product $product, UploadedFile $photo): VerificationAttempt
    {
        $attempt = $this->openAttempt($user, $product);
        $path = $this->store($photo);

        try {
            $assessment = $this->ai->verifyOwnership(
                $product,
                $attempt->generated_code,
                (string) Storage::disk($this->disk())->get($path),
                $photo->getMimeType() ?? 'image/jpeg',
            );
        } catch (AiUnavailable) {
            /*
             * The photograph is left in place deliberately: the queued job needs it, and
             * deletes it there on exactly the same terms. The path travels in the job
             * payload and nowhere else, which is why it leaves as an exception property
             * rather than as a column or a return value.
             */
            throw new VerificationQueued($attempt->id, $path);
        }

        $this->conclude($attempt, $assessment, $path);

        return $attempt->refresh();
    }

    /**
     * Records the outcome and destroys the photograph.
     *
     * Called from here on the synchronous path and from CompleteVerification on the
     * queued one, so there is exactly one place that concludes an attempt and exactly
     * one place that deletes a photograph.
     */
    public function conclude(
        VerificationAttempt $attempt,
        OwnershipAssessment $assessment,
        ?string $photoPath,
    ): void {
        $this->deletePhoto($photoPath);

        $attempt->forceFill([
            'outcome' => $assessment->passed
                ? VerificationAttempt::OUTCOME_PASSED
                : VerificationAttempt::OUTCOME_FAILED,
            // Survives the photograph, so a failure can still be explained.
            'ai_reasoning' => $assessment->reason,
            'photo_deleted_at' => now(),
        ])->save();
    }

    /**
     * Removes a photograph, if one is still there.
     *
     * Tolerant of a missing file on purpose. A retried job, or a cleanup sweep that got
     * there first, must not fail the attempt: the goal is that the file is gone, and it
     * being gone already satisfies that.
     */
    public function deletePhoto(?string $path): void
    {
        if ($path === null) {
            return;
        }

        Storage::disk($this->disk())->delete($path);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** The open attempt, or a new one, with the ceiling checked either way. */
    private function openAttempt(User $user, Product $product): VerificationAttempt
    {
        $this->assertCanAttempt($user, $product);

        return VerificationAttempt::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where('outcome', VerificationAttempt::OUTCOME_PENDING)
            ->latest('id')
            ->first()
            ?? $this->start($user, $product);
    }

    /**
     * The ceiling, and the already verified case.
     *
     * Five per user per product. Reaching it closes this product to this user
     * permanently: there is no appeal, no administrator reset, and no way to buy more,
     * which is deliberate and listed among the things not to build.
     */
    private function assertCanAttempt(User $user, Product $product): void
    {
        if ($this->isVerifiedFor($user, $product)) {
            throw ApiException::forbidden('You have already verified this product.');
        }

        if ($this->concludedCount($user, $product) >= VerificationAttempt::MAX_ATTEMPTS) {
            throw ApiException::attemptsExhausted();
        }
    }

    private function concludedCount(User $user, Product $product): int
    {
        return VerificationAttempt::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->whereIn('outcome', [
                VerificationAttempt::OUTCOME_PASSED,
                VerificationAttempt::OUTCOME_FAILED,
            ])
            ->count();
    }

    /**
     * A short code that is easy to write by hand and hard to misread.
     *
     * No O, I, or 1, because the buyer writes this on paper and somebody, or something,
     * has to read it back from a photograph.
     */
    private function issueCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($code, 0, 2).'-'.substr($code, 2);
    }

    /** Onto the private disk, which serves no URLs and never will. */
    private function store(UploadedFile $photo): string
    {
        $path = 'attempts/'.Str::uuid()->toString().'.'.($photo->extension() ?: 'jpg');

        Storage::disk($this->disk())->put($path, (string) file_get_contents($photo->getRealPath()));

        return $path;
    }

    private function disk(): string
    {
        return (string) config('filesystems.verification_photos', 'verification_photos');
    }
}
