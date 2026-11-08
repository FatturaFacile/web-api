<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

/**
 * Input of a cancellation: immediate (refunding the unused part of the billed period when asked) or at the end of
 * the term, with a reason code and a free text.
 */
final readonly class CancelSubscriptionDTO
{
    public const array REASON_CODES = ['too_expensive', 'missing_features', 'switched_competitor', 'unused', 'customer_service', 'technical_issues', 'business_closed', 'non_payment', 'other'];

    public const array TYPES = ['immediate', 'end_of_term'];

    public function __construct(
        public string $subscriptionId,
        public string $reason,
        public ?string $cancellationDate = null,
        public ?string $actorUserId = null,
        public string $reasonCode = 'other',
        public string $cancellationType = 'immediate',
        public bool $refund = true,
    ) {}
}
