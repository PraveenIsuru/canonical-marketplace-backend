<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Puts the stored live flag back in step with the attachments underneath it.
 *
 * Invariant 12 says a store is visible to buyers if and only if it holds at least one
 * attachment. The flag is stored rather than computed, because the alternative is a
 * correlated subquery against the largest table in the system on every product page
 * render, and it is kept in step by model events on the attachment.
 *
 * ## The hole this closes
 *
 * Model events only fire for writes that go through a model. A bulk delete, a manual
 * fix applied in psql, a restore from a backup, or a future code path that reaches for
 * a query builder to be quick, all change attachments without any event firing. M8
 * noted this and answered it with a rule: attachments are deleted through the model,
 * never in bulk. A rule is a good defence and not a complete one, because it depends on
 * everybody who touches this code knowing it.
 *
 * This job is the part that does not depend on anybody remembering. It reads what is
 * actually there and corrects the flag, so the invariant becomes something the platform
 * repairs rather than something it merely intends.
 *
 * Both directions are wrong in their own way and both are fixed. A store marked live
 * with nothing on its shelves sends buyers to an empty page. A store marked dark that
 * does hold stock is a seller who is invisible and losing business without knowing it,
 * which is the worse of the two and the reason this is not simply a report.
 *
 * Soft deleted stores are skipped. They are excluded from every catalogue query
 * already, so their flag has no effect on anything a buyer sees, and writing to them
 * would only add noise to the log.
 */
final class ReconcileStoreLiveFlags implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * One reconciliation at a time.
     *
     * Two runs would reach the same conclusion and write it twice, which is harmless in
     * the database and misleading in the log, since a correction would appear to have
     * been needed twice over.
     */
    public int $uniqueFor = 3600;

    /**
     * The stores whose flag was wrong, and which way.
     *
     * @var array<int, array{id: int, name: string, was: bool, now: bool}>
     */
    public array $corrections = [];

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        /*
         * One query for the truth, rather than a query per store.
         *
         * `exists` on each of a few thousand stores would be a few thousand round trips
         * for a job that almost always finds nothing to do. A left join and a group by
         * answers it once, and a store with no attachments comes back with a count of
         * zero rather than being absent, which is exactly the case being looked for.
         */
        $actual = DB::table('stores')
            ->leftJoin('attachments', 'attachments.store_id', '=', 'stores.id')
            ->whereNull('stores.deleted_at')
            ->groupBy('stores.id', 'stores.is_live')
            ->select('stores.id', 'stores.is_live')
            ->selectRaw('COUNT(attachments.id) as attachment_count')
            ->get();

        $wrong = $actual->filter(
            static fn ($row): bool => (bool) $row->is_live !== ((int) $row->attachment_count > 0)
        );

        if ($wrong->isEmpty()) {
            return;
        }

        /*
         * Corrected through the model rather than by a bulk update, which is the same
         * rule the detach path follows and for the same reason. `recomputeLiveFlag`
         * saves, the save fires the model event, and the event invalidates the cached
         * product pages the store appears on. A bulk update here would fix the column
         * and leave every one of those pages still counting a shop that is no longer
         * there, which is a subtler version of the bug this job exists to repair.
         */
        foreach (Store::query()->whereIn('id', $wrong->pluck('id'))->get() as $store) {
            $before = $store->is_live;

            $store->recomputeLiveFlag();

            $this->corrections[] = [
                'id' => $store->id,
                'name' => $store->name,
                'was' => $before,
                'now' => $store->is_live,
            ];
        }
    }
}
