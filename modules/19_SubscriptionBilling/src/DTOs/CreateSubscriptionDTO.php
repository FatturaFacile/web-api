<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

/**
 * Input of a new subscription. Amounts and quantities are decimal strings.
 */
final readonly class CreateSubscriptionDTO
{
    /**
     * @param  array<int, array{item_type?: string, description?: string, quantity?: string|int|float, unit_price?: string|int|float, discount_percentage?: string|int|float, tax_rate?: string|int|float|null, metric_id?: string|null, catalog_product_id?: string|null, legacy_item_id?: string|null}>  $items  extra charges and metered items besides the plan
     * @param  array<string, mixed>  $metadata
     * @param  int|null  $trialDays  null takes the trial of the plan
     * @param  string|null  $termEndDate  explicit end of the first term (legacy subscriptions); null computes it from the term months
     * @param  bool  $includePlanItem  false for legacy subscriptions, whose charges are only their own items
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $customerId,
        public string $planId,
        public string $startDate,
        public int $contractTermMonths = 12,
        public bool $autoRenew = true,
        public int $billingDayOfMonth = 1,
        public int $paymentTermsDays = 30,
        public string $paymentMethod = 'sepa_direct_debit',
        public ?string $bankAccountId = null,
        public array $items = [],
        public array $metadata = [],
        public ?string $name = null,
        public string $quantity = '1',
        public ?int $trialDays = null,
        public ?string $billingAlignment = null,
        public int $renewalNoticeDays = 30,
        public int $cancellationNoticeDays = 30,
        public ?string $renewalPriceIncreasePercent = null,
        public ?string $termEndDate = null,
        public bool $includePlanItem = true,
        public ?string $notes = null,
        public ?string $currency = null,
        public ?string $legacySubscriptionId = null,
        public ?string $subscriptionId = null,
    ) {}
}
