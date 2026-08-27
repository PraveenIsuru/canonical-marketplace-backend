<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A paused attachment flow, holding the questions that were put to the seller.
 *
 * The questions live here rather than travelling with the client because completeness
 * has to be checkable. A client that supplied both the questions and the answers could
 * always claim it answered them all.
 *
 * @property string $id
 * @property int $store_id
 * @property string $type
 * @property int|null $product_id
 * @property array<int, array{id: string, attribute: string, text: string}> $questions
 * @property array<string, mixed> $draft
 * @property Carbon $expires_at
 */
class AttachSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const TYPE_WIZARD = 'wizard';

    public const TYPE_CONFIRMATION = 'confirmation';

    /**
     * Long enough to answer a page of questions without hurrying, short enough that a
     * session abandoned weeks ago cannot be submitted against a record that has moved
     * on since.
     */
    public const LIFETIME_HOURS = 24;

    protected $fillable = ['store_id', 'type', 'product_id', 'questions', 'draft', 'ai_job_id', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'draft' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * The ids of every question this session asked.
     *
     * @return array<int, string>
     */
    public function questionIds(): array
    {
        return array_values(array_map(
            static fn (array $question): string => $question['id'],
            $this->questions,
        ));
    }
}
