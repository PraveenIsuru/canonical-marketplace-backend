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
