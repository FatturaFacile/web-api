<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Warranty;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\ServiceManagement\Enums\WarrantyPolicyType;

final readonly class WarrantyResult
{
    public const SOURCE_STATUTORY = 'statutory';

    public const SOURCE_CONTRACT_CLAUSE = 'contract_clause';

    public const SOURCE_EXTENSION = 'extension';

    public function __construct(
        public CarbonImmutable $startDate,
        public CarbonImmutable $expiryDate,
        public WarrantyPolicyType $policyType,
        public int $months,
        public string $source,
        public string $jurisdiction,
        public string $customerType,
    ) {}

    public function withExtension(ExtendedCarePackExtension $extension): self
    {
        return new self(
            startDate: $this->startDate,
            expiryDate: $extension->endsAt,
            policyType: WarrantyPolicyType::ExtendedCarePack,
            months: $this->months,
            source: self::SOURCE_EXTENSION,
            jurisdiction: $this->jurisdiction,
            customerType: $this->customerType,
        );
    }

    public function isInWarrantyAt(CarbonInterface $date): bool
    {
        return $date->toDateString() <= $this->expiryDate->toDateString();
    }

    /**
     * Whole calendar days left until expiry (0 on the expiry day, negative once expired).
     */
    public function daysRemainingAt(CarbonInterface $date): int
    {
        $from = CarbonImmutable::parse($date->toDateString(), 'UTC');
        $to = CarbonImmutable::parse($this->expiryDate->toDateString(), 'UTC');

        return intdiv($to->getTimestamp() - $from->getTimestamp(), 86400);
    }

    /**
     * @return array{status: string, start_date: string, expiry_date: string, days_remaining: int, policy_type: string, basis: array{jurisdiction: string, customer_type: string, months: int, source: string}}
     */
    public function evaluationAt(CarbonInterface $date): array
    {
        return [
            'status' => $this->isInWarrantyAt($date) ? 'in_warranty' : 'out_of_warranty',
            'start_date' => $this->startDate->toDateString(),
            'expiry_date' => $this->expiryDate->toDateString(),
            'days_remaining' => max(0, $this->daysRemainingAt($date)),
            'policy_type' => $this->policyType->value,
            'basis' => $this->basis(),
        ];
    }

    /**
     * @return array{jurisdiction: string, customer_type: string, months: int, source: string}
     */
    public function basis(): array
    {
        return [
            'jurisdiction' => $this->jurisdiction,
            'customer_type' => $this->customerType,
            'months' => $this->months,
            'source' => $this->source,
        ];
    }
}
