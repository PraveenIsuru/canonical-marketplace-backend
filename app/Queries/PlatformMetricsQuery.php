<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\CommunityPost;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\Proposal;
use App\Models\Store;
use App\Models\VerificationAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The platform at a glance (EP-45).
 *
 * Counts, not analytics. There is no time series here and no comparison against a
 * previous period, because this endpoint answers "is anything wrong right now" rather
 * than "how are we doing", and the two want different screens.
 *
 * **Nothing here is per user.** No figure names a person, counts a person's activity,
 * or could be narrowed to one: the closest is a count of users who have verified
 * something, which is a number and not a list.
 */
final class PlatformMetricsQuery
{
    /**
     * @return array{
     *     products: array{total: int, with_sellers: int, without_sellers: int},
     *     stores: array{total: int, live: int, dark: int},
     *     proposals: array{pending: int, escalated: int, approved: int, rejected: int},
     *     community: array{posts: int, verified_users: int},
     *     views: array{last_7_days: int, last_30_days: int},
     *     oldest_escalation_opened_at: string|null
     * }
     */
    public function snapshot(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        $products = Product::query()->count();
        $withSellers = Product::query()->whereHas('attachments')->count();

        $stores = Store::query()->count();
        $live = Store::query()->where('is_live', true)->count();

        $byStatus = Proposal::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'products' => [
                'total' => $products,
                'with_sellers' => $withSellers,
                // Derived rather than counted again. A product is one or the other, and
                // two queries could disagree if a listing changed between them.
                'without_sellers' => $products - $withSellers,
            ],
            'stores' => [
                'total' => $stores,
                'live' => $live,
                // A dark store holds no attachments. Invariant 12, counted the same way
                // the flag is maintained.
                'dark' => $stores - $live,
            ],
            'proposals' => [
                'pending' => (int) ($byStatus[Proposal::STATUS_PENDING] ?? 0),
                'escalated' => (int) ($byStatus[Proposal::STATUS_ESCALATED] ?? 0),
                'approved' => (int) ($byStatus[Proposal::STATUS_APPROVED] ?? 0),
                'rejected' => (int) ($byStatus[Proposal::STATUS_REJECTED] ?? 0),
            ],
            'community' => [
                // Soft deleted posts are excluded by the model's own scope, so a
                // moderated post stops being counted the moment it is hidden.
                'posts' => CommunityPost::query()->count(),
                'verified_users' => VerificationAttempt::query()
                    ->where('outcome', 'passed')
                    ->distinct()
                    ->count('user_id'),
            ],
            'views' => [
                // UTC days, matching section 11.11, so this agrees with what a seller
                // reads on their own analytics rather than being a second reckoning.
                'last_7_days' => $this->viewsSince($today->subDays(6)),
                'last_30_days' => $this->viewsSince($today->subDays(29)),
            ],
            /*
             * The one figure here that names an obligation rather than a fact. While it
             * is set, a seller is blocked and waiting on an administrator, and the queue
             * that answers them is EP-40.
             */
            'oldest_escalation_opened_at' => Proposal::query()
                ->where('status', Proposal::STATUS_ESCALATED)
                ->min('review_opens_at'),
        ];
    }

    private function viewsSince(CarbonImmutable $from): int
    {
        return ProductView::query()->where('viewed_at', '>=', $from)->count();
    }
}
