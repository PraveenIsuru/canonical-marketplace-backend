<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReconcileStoreLiveFlags;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\MaintenanceHealthAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Asks whether the recurring work is actually having its effect.
 *
 * The build plan singles out the review window sweep as needing monitoring rather than
 * best effort execution, because a missed run leaves a seller unable to trade and
 * nothing else in the platform notices. This is that monitoring.
 *
 * ## Why this checks outcomes and not whether jobs ran
 *
 * The obvious design is a heartbeat: each job records that it ran, and an alarm fires
 * when a heartbeat goes stale. It is worse than it looks. A heartbeat only reports
 * while the thing recording it is working, it needs storage that survives a routine
 * cache clear, and above all it answers the wrong question. A sweep that ran on time
 * and resolved nothing because of a bug leaves a perfectly fresh heartbeat and a seller
 * who is still blocked.
 *
 * So each check below reads the state a working system would leave behind, and reports
 * the gap. That is true regardless of the cause: a stopped scheduler, a dead worker, a
 * failing job, a queue connection pointed at nothing, or a mistake in the resolution
 * matrix all show up the same way, because the seller is blocked either way.
 *
 * Horizon covers the other half of this and neither replaces the other. Horizon can say
 * a job threw, how long it waited, and how many are queued. It cannot say that a job
 * which is not being dispatched at all should have been.
 *
 * Exits non zero when anything is wrong, so an external monitor can key off the exit
 * code without parsing the output.
 */
final class CheckMaintenanceHealth extends Command
{
    protected $signature = 'maintenance:health {--no-notify : Report without emailing anyone}';

    protected $description = 'Report whether the scheduled work is having its effect';

    public function handle(): int
    {
        /** @var array<int, string> $faults */
        $faults = array_values(array_filter([
            $this->blockedSellers(),
            $this->orphanedPhotographs(),
            $this->staleLiveFlags(),
        ]));

        if ($faults === []) {
            $this->info('Scheduled work is having its effect. Nothing is overdue.');

            return self::SUCCESS;
        }

        foreach ($faults as $fault) {
            $this->error($fault);
        }

        if (! $this->option('no-notify')) {
            $this->notifyAdministrators($faults);
        }

        return self::FAILURE;
    }

    /**
     * The check that matters most: somebody cannot sell something.
     *
     * Counted from `review_closes_at` rather than from when the proposal opened,
     * because a proposal inside its window is waiting by design and only one past its
     * window is waiting by fault. The oldest is named as well as the count, since the
     * age is what says whether this started an hour ago or a fortnight ago.
     */
    private function blockedSellers(): ?string
    {
        $grace = (int) config('maintenance.health.proposal_overdue_hours');

        $overdue = Proposal::query()
            ->where('status', Proposal::STATUS_PENDING)
            ->where('review_closes_at', '<=', now()->subHours($grace))
            ->orderBy('review_closes_at')
            ->get();

        if ($overdue->isEmpty()) {
            return null;
        }

        $oldest = $overdue->first();

        return sprintf(
            '%d proposal(s) are past their review window and have not resolved. The oldest closed %s. '
            .'Each one is a seller blocked from selling that product with no automatic route out. '
            .'Check that the review window sweep is running.',
            $overdue->count(),
            $oldest->review_closes_at->diffForHumans(),
        );
    }

    /**
     * Invariant 7: a photograph never outlives its verification.
     *
     * The threshold is well past the cleanup's own age limit and its daily schedule, so
     * a file waiting for tonight's run is not a fault. One still here after that means
     * nothing is deleting them.
     */
    private function orphanedPhotographs(): ?string
    {
        $disk = (string) config('filesystems.verification_photos', 'verification_photos');
        $storage = Storage::disk($disk);

        $cutoff = now()->subHours((int) config('maintenance.health.orphan_photograph_hours'))->getTimestamp();

        $stale = 0;

        foreach ($storage->allFiles('attempts') as $path) {
            if ($storage->lastModified($path) <= $cutoff) {
                $stale++;
            }
        }

        if ($stale === 0) {
            return null;
        }

        return sprintf(
            '%d verification photograph(s) have outlived their verification. Invariant 7 says a '
            .'photograph is deleted whether verification passed or failed. Check that the '
            .'photograph cleanup is running.',
            $stale,
        );
    }

    /**
     * Invariant 12: a store is visible if and only if it holds an attachment.
     *
     * Read only. Reconciliation is a separate job with its own schedule, and a check
     * that quietly repaired what it found would hide how often the flag goes out of
     * step, which is the interesting part. A flag drifting means an attachment changed
     * without a model event, and that is worth tracing rather than papering over.
     */
    private function staleLiveFlags(): ?string
    {
        $wrong = DB::table('stores')
            ->leftJoin('attachments', 'attachments.store_id', '=', 'stores.id')
            ->whereNull('stores.deleted_at')
            ->groupBy('stores.id', 'stores.is_live')
            ->select('stores.id', 'stores.is_live')
            ->selectRaw('COUNT(attachments.id) as attachment_count')
            ->get()
            ->filter(static fn ($row): bool => (bool) $row->is_live !== ((int) $row->attachment_count > 0))
            ->count();

        if ($wrong === 0) {
            return null;
        }

        return sprintf(
            '%d store visibility flag(s) do not match their attachments. Run %s to correct them, '
            .'then trace what changed an attachment without firing a model event.',
            $wrong,
            ReconcileStoreLiveFlags::class,
        );
    }

    /**
     * @param  array<int, string>  $faults
     */
    private function notifyAdministrators(array $faults): void
    {
        $address = config('maintenance.health.notify');

        if (is_string($address) && $address !== '') {
            Notification::route('mail', $address)->notify(new MaintenanceHealthAlert($faults));

            return;
        }

        /*
         * Every administrator, rather than a single operations address, because
         * administrators are already the people who resolve escalations and they are
         * the ones who can act on the most serious of these faults by hand.
         */
        $administrators = User::query()->where('is_admin', true)->get();

        if ($administrators->isEmpty()) {
            $this->warn('No administrator accounts exist, so nobody was emailed about this.');

            return;
        }

        Notification::send($administrators, new MaintenanceHealthAlert($faults));
    }
}
