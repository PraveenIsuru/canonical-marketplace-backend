<?php

declare(strict_types=1);

namespace App\Services\Admin;

/**
 * What an administrator's decision did (EP-41, EP-42), per section 11.12.
 *
 * One shape for both endpoints, because an administrator reading the result cares
 * about the same four things either way: what the proposal says now, which version the
 * decision wrote, whether the withheld listing was released, and whether the seller can
 * trade again.
 */
final readonly class AdminDecision
{
    public function __construct(
        public int $proposalId,
        public string $status,
        public string $resolvedAt,
        /** Null where the decision wrote no version, which is every rejection at EP-41. */
        public ?int $versionNumber,
        public int $attachmentsCreated,
        /**
         * True on **both** outcomes of EP-41, and that is the point of the field.
         *
         * What blocked the seller was an unresolved proposal, not an unfavourable one.
         * A rejected seller keeps no listing and gets no version, and is free to start
         * a fresh attempt immediately.
         */
        public bool $sellerUnblocked,
    ) {}
}
