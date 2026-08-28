<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reviewer's vote on one proposal.
 *
 * Immutable. There is no updated_at and no soft delete, and no endpoint changes a vote
 * once cast: a reviewer who changes their mind has no recourse, which is deliberate,
 * because a vote that can be revised turns a three day window into a negotiation.
 *
 * The absence of a row is what makes a reviewer a **non voter**, and non voters are
 * excluded from the denominator. That is why abstention is not stored as a third vote
 * value: it is not a position, it is the lack of one.
 *
 * created_at is set by the database on insert and has no updated_at partner, because a
 * vote is written once and never revised.
 *
 * @property int $id
 * @property int $proposal_id
 * @property int $store_id
 * @property bool $vote true is in favour
 * @property string|null $comment
 * @property Carbon $created_at
 */
class ProposalVote extends Model
{
    public $timestamps = false;

    protected $fillable = ['proposal_id', 'store_id', 'vote', 'comment'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'vote' => 'boolean',
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
