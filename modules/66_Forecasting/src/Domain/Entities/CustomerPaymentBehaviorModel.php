<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Entities;

use DateTimeImmutable;
use Modules\Forecasting\Domain\Enums\PaymentRiskTier;
use Modules\Forecasting\Domain\Services\NormalDistribution;

/**
 * What the ledger says about how a customer pays: the recency-weighted delay after the contractual due date.
 */
final readonly class CustomerPaymentBehaviorModel
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $customerId,
        public int $sampleSize,
        public float $averageDaysLate,
        public float $stdDevDaysLate,
        public float $onTimePaymentProbability,
        public int $predictedCollectionDateOffsetDays,
        public PaymentRiskTier $paymentRiskTier,
        public DateTimeImmutable $lastTrainedAt,
    ) {}

    /**
     * Expected collection date of an invoice: the due date shifted by the predicted offset.
     */
    public function predictCollectionDate(DateTimeImmutable $dueDate): DateTimeImmutable
    {
        return $this->shift($dueDate, $this->predictedCollectionDateOffsetDays);
    }

    /**
     * Collection date quantiles of an invoice. The keys name the percentile of the date, so p10 is the earliest plausible
     * collection and p90 the latest; the cash received is pessimistic at p90 and optimistic at p10.
     *
     * @return array{p10: DateTimeImmutable, p50: DateTimeImmutable, p90: DateTimeImmutable}
     */
    public function calculateInvoiceConfidenceWindow(DateTimeImmutable $dueDate): array
    {
        $spread = NormalDistribution::Z80 * $this->stdDevDaysLate;

        return [
            'p10' => $this->shift($dueDate, (int) round($this->predictedCollectionDateOffsetDays - $spread)),
            'p50' => $this->predictCollectionDate($dueDate),
            'p90' => $this->shift($dueDate, (int) round($this->predictedCollectionDateOffsetDays + $spread)),
        ];
    }

    private function shift(DateTimeImmutable $date, int $days): DateTimeImmutable
    {
        return $date->modify(sprintf('%+d days', $days));
    }
}
