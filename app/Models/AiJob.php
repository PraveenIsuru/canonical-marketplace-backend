<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit of AI work that was queued because the provider was unavailable.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $type
 * @property string $status
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $result
 */
class AiJob extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'type', 'status', 'payload', 'result', 'failure_reason', 'completed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TYPE_SEARCH_INTERPRETATION = 'search_interpretation';

    public const TYPE_MATCH_CANDIDATES = 'match_candidates';

    public const TYPE_WIZARD_QUESTIONS = 'wizard_questions';

    public const TYPE_CONFIRMATION_QUESTIONS = 'confirmation_questions';

    /**
     * The outcome of a confirmation submission that had to be finished on the queue.
     *
     * Not the confidence score. The score is what the provider was asked for, but what
     * the client resumes from is the attach or proposal outcome, and the score itself
     * reaches no response body.
     */
    public const TYPE_CONFIRMATION_OUTCOME = 'confirmation_outcome';

    /**
     * M9. What a queued verification judgement completes as.
     *
     * Its result is the outcome and the reason, exactly as EP-35 would have returned
     * them. It never carries the photograph or where it was: the file is deleted the
     * moment the job concludes, on a pass and on a failure alike.
     */
    public const TYPE_VERIFICATION_RESULT = 'verification_result';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  array<string, mixed>  $result */
    public function markCompleted(array $result): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'result' => $result,
            'completed_at' => now(),
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'failure_reason' => $reason,
            'completed_at' => now(),
        ])->save();
    }
}
