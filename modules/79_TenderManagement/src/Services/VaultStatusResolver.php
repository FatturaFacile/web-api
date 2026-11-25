<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Carbon\CarbonImmutable;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Domain\Enums\VerificationStatus;
use Modules\TenderManagement\Models\QualificationVaultDocument;

/**
 * Validity of a vault document at a date: the effective expiry (the printed expiry, the SOA five year term, the rolling three
 * month rule of the Swiss debt-enforcement extract) and the resulting traffic-light status.
 */
final class VaultStatusResolver
{
    /**
     * The date after which the document is no longer acceptable; null when it never expires.
     */
    public function effectiveExpiry(QualificationVaultDocument $document): ?CarbonImmutable
    {
        $candidates = [];

        if ($document->expiry_date !== null) {
            $candidates[] = CarbonImmutable::parse($document->expiry_date->toDateString());
        }

        if ($document->issue_date !== null) {
            $issued = CarbonImmutable::parse($document->issue_date->toDateString());
            $months = $document->doc_type->maxAgeMonths();

            if ($months !== null) {
                $candidates[] = $issued->addMonthsNoOverflow($months);
            }

            if ($document->doc_type === VaultDocType::ItSoa && $document->expiry_date === null) {
                $candidates[] = $issued->addYears((int) config('tender_management.soa.validity_years', 5));
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $candidates[0];
    }

    /**
     * Days of validity left (negative when expired); null when the document never expires.
     */
    public function daysToExpiry(QualificationVaultDocument $document, CarbonImmutable $today): ?int
    {
        $expiry = $this->effectiveExpiry($document);

        return $expiry === null ? null : (int) $today->startOfDay()->diffInDays($expiry->startOfDay(), false);
    }

    public function expiringSoonDays(VaultDocType $type): int
    {
        return match ($type) {
            VaultDocType::ChBetreibungsauszug, VaultDocType::ItDurc => 30,
            default => (int) (config('tender_management.alerts.expiry_offsets_days')[0] ?? 90),
        };
    }

    public function statusAt(QualificationVaultDocument $document, CarbonImmutable $today): VerificationStatus
    {
        $days = $this->daysToExpiry($document, $today);

        if ($days !== null && $days < 0) {
            return VerificationStatus::Expired;
        }

        if ($document->verification_status === VerificationStatus::PendingRenewal) {
            return VerificationStatus::PendingRenewal;
        }

        if ($days !== null && $days <= $this->expiringSoonDays($document->doc_type)) {
            return VerificationStatus::ExpiringSoon;
        }

        return VerificationStatus::Valid;
    }

    /**
     * Whether the document can be relied upon in a bid at this date.
     */
    public function isUsable(QualificationVaultDocument $document, CarbonImmutable $today): bool
    {
        return $this->statusAt($document, $today) !== VerificationStatus::Expired;
    }

    /**
     * Writes the status when it changed; returns whether it did.
     */
    public function sync(QualificationVaultDocument $document, CarbonImmutable $today): bool
    {
        $status = $this->statusAt($document, $today);

        if ($document->verification_status === $status) {
            return false;
        }

        $document->forceFill(['verification_status' => $status])->save();

        return true;
    }
}
