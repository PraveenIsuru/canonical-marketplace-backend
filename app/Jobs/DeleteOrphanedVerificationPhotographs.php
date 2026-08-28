<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Community\VerificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Destroys verification photographs nothing is coming back for.
 *
 * A safety net, not the primary mechanism. Photographs are deleted the moment
 * verification concludes, on a pass and on a failure alike, and the queued job deletes
 * them too when the provider never recovers. This exists for the case none of those
 * cover: a worker killed mid job, a queue drained without running, a crash between the
 * upload and the judgement.
 *
 * The invariant is not "we usually delete verification photographs". It is that a
 * photograph is deleted once verification concludes, and a photograph whose
 * verification can never conclude has concluded in the only sense that matters here.
 * This is what makes that true even when a process dies at the wrong moment.
 */
final class DeleteOrphanedVerificationPhotographs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * One cleanup at a time.
     *
     * Two runs would walk the same directory and try to delete the same files, and the
     * loser of each race would be deleting a path that no longer exists.
     */
    public int $uniqueFor = 3600;

    /** How many files the run removed, readable afterwards by whoever dispatched it. */
    public int $removed = 0;

    public function __construct(public readonly int $olderThanHours = 6)
    {
        $this->onQueue('maintenance');
    }

    public function handle(VerificationService $verification): void
    {
        $cutoff = now()->subHours(max(1, $this->olderThanHours))->getTimestamp();

        $disk = (string) config('filesystems.verification_photos', 'verification_photos');
        $storage = Storage::disk($disk);

        foreach ($storage->allFiles('attempts') as $path) {
            /*
             * Age is read from the file rather than from any row, deliberately. The row
             * does not know the path, which is the whole design, so the file's own
             * modification time is the only thing that can decide this.
             *
             * The window is generous because a photograph still being judged must not be
             * pulled out from under a running job. Six hours is far beyond any provider
             * call and far short of leaving one lying about.
             */
            if ($storage->lastModified($path) > $cutoff) {
                continue;
            }

            $verification->deletePhoto($path);
            $this->removed++;
        }
    }
}
