<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One store entitled to vote on one proposal.
 *
 * Written when the proposal opens and never afterwards. A store that attaches to the
 * product on day two of the window gets no row and cannot vote, and a store that
 * detaches keeps its row and its vote. That is the point: eligibility is a fact about
 * a moment, not about the present.
 *
 * @property int $id
 * @property int $proposal_id
 * @property int $store_id
 * @property Carbon|null $notified_at
 */
class ProposalReviewer extends Model
{
    public $timestamps = false;

    protected $fillable = ['proposal_id', 'store_id', 'notified_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
