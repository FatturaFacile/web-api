<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Strategies;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\TenderManagement\Contracts\TenderProcurementComplianceStrategyInterface;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Domain\ValueObjects\CompanyProfile;
use Modules\TenderManagement\Domain\ValueObjects\ComplianceIssue;
use Modules\TenderManagement\Domain\ValueObjects\CriterionResult;
use Modules\TenderManagement\Domain\ValueObjects\OfferInput;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Services\VaultStatusResolver;

/**
 * Behaviour shared by the regimes: the financial and technical requirements, the certification rows and the deadline checks.
 */
abstract class AbstractTenderStrategy implements TenderProcurementComplianceStrategyInterface
{
    public function __construct(protected readonly VaultStatusResolver $status) {}

    public function validateNotice(TenderNotice $notice): array
    {
        return [];
    }

    public function validateEconomicOffer(TenderNotice $notice, OfferInput $input): array
    {
        return [];
    }

    public function anacContribution(TenderNotice $notice): ?string
    {
        return null;
    }

    public function bidBondReductionKeys(): array
    {
        return [];
    }

    public function supportsEdgue(): bool
    {
        return false;
    }

    public function validateVaultDocument(QualificationVaultDocument $document, CarbonImmutable $today): array
    {
        $issues = [];
        $days = $this->status->daysToExpiry($document, $today);

        if ($days !== null && $days < 0) {
            $issues[] = ComplianceIssue::blocking('document_expired', ['type' => $document->doc_type->value]);
        } elseif ($days !== null && $days <= $this->status->expiringSoonDays($document->doc_type)) {
            $issues[] = ComplianceIssue::warning('document_expiring', ['type' => $document->doc_type->value, 'days' => $days]);
        }

        return $issues;
    }

    public function evaluateRequirements(TenderNotice $notice, CompanyProfile $profile, Collection $vault, CarbonImmutable $today): array
    {
        $rows = [];

        if ($notice->required_min_turnover !== null && Dec::isPositive($notice->required_min_turnover)) {
            $gap = Dec::max(Dec::sub($notice->required_min_turnover, $profile->turnover), 0);
            $rows[] = new CriterionResult('turnover', CriterionResult::Financial, Dec::money($notice->required_min_turnover), Dec::money($profile->turnover), Dec::cmp($gap, 0) === 0, Dec::money($gap), 20);
        }

        if ($notice->required_similar_projects_count > 0) {
            $available = $profile->similarProjectsCount;
            $rows[] = new CriterionResult('similar_projects', CriterionResult::Technical, $notice->required_similar_projects_count, $available, $available >= $notice->required_similar_projects_count, max(0, $notice->required_similar_projects_count - $available), 15);
        }

        foreach ($notice->required_certifications ?? [] as $type) {
            $document = $this->usable($vault, VaultDocType::tryFrom($type), $today);
            $rows[] = new CriterionResult('certification:'.$type, CriterionResult::Certification, $type, $document?->document_number, $document !== null, $document === null ? $type : null, 5, $document?->id);
        }

        return $rows;
    }

    /**
     * The most recent usable document of a type.
     *
     * @param  Collection<int, QualificationVaultDocument>  $vault
     */
    protected function usable(Collection $vault, ?VaultDocType $type, CarbonImmutable $today): ?QualificationVaultDocument
    {
        if ($type === null) {
            return null;
        }

        return $vault
            ->filter(fn (QualificationVaultDocument $document): bool => $document->doc_type === $type && $this->status->isUsable($document, $today))
            ->sortByDesc(fn (QualificationVaultDocument $document): string => (string) $document->issue_date?->toDateString())
            ->first();
    }

    /**
     * A mandatory vault document: present, valid and not about to expire.
     *
     * @param  Collection<int, QualificationVaultDocument>  $vault
     * @return list<ComplianceIssue>
     */
    protected function requireDocument(Collection $vault, VaultDocType $type, CarbonImmutable $today, ?string $missingCode = null, ?string $expiredCode = null): array
    {
        $present = $vault->filter(fn (QualificationVaultDocument $document): bool => $document->doc_type === $type);

        if ($present->isEmpty()) {
            return [ComplianceIssue::blocking($missingCode ?? 'document_missing', ['type' => $type->value], 'administrative')];
        }

        $document = $this->usable($vault, $type, $today);

        if ($document === null) {
            return [ComplianceIssue::blocking($expiredCode ?? 'document_expired', ['type' => $type->value], 'administrative')];
        }

        $days = $this->status->daysToExpiry($document, $today);

        if ($days !== null && $days <= $this->status->expiringSoonDays($type)) {
            return [ComplianceIssue::warning('document_expiring', ['type' => $type->value, 'days' => $days], 'administrative')];
        }

        return [];
    }

    /**
     * @return list<ComplianceIssue>
     */
    protected function deadlineIssues(TenderNotice $notice, CarbonImmutable $now): array
    {
        $deadline = $notice->submission_peremptory_deadline_at;

        return $deadline !== null && $now->greaterThan(CarbonImmutable::parse($deadline))
            ? [ComplianceIssue::blocking('submission_deadline_passed')]
            : [];
    }

    /**
     * Envelope documents every regime asks for, as a base the regimes extend.
     *
     * @param  list<array{0: string, 1: bool, 2: bool}>  $rows  [key, required, signature required]
     * @return list<array{key: string, required: bool, signature_required: bool}>
     */
    protected function checklist(array $rows): array
    {
        return array_map(static fn (array $row): array => ['key' => $row[0], 'required' => $row[1], 'signature_required' => $row[2]], $rows);
    }
}
