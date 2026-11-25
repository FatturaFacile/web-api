<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Domain\ValueObjects\CompanyProfile;
use Modules\TenderManagement\Domain\ValueObjects\ComplianceIssue;
use Modules\TenderManagement\Domain\ValueObjects\CriterionResult;
use Modules\TenderManagement\Domain\ValueObjects\OfferInput;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Models\TenderParticipation;

/**
 * The legal regime of a tender: Swiss simap.ch (LAPub / BöB, CIAP / IVöB), Italian D.Lgs. 36/2023 with ANAC and MePA, or the
 * generic regime of EU TED and private requests for proposal. One implementation per regime.
 */
interface TenderProcurementComplianceStrategyInterface
{
    public function supports(Jurisdiction $jurisdiction): bool;

    /**
     * IT, CH or GENERIC: the group of vault documents and envelope checklists the regime uses.
     */
    public function regime(): string;

    /**
     * @return list<ComplianceIssue>
     */
    public function validateNotice(TenderNotice $notice): array;

    /**
     * @return list<ComplianceIssue>
     */
    public function validateVaultDocument(QualificationVaultDocument $document, CarbonImmutable $today): array;

    /**
     * The requirements of the notice against the company data and the (non superseded) vault documents.
     *
     * @param  Collection<int, QualificationVaultDocument>  $vault
     * @return list<CriterionResult>
     */
    public function evaluateRequirements(TenderNotice $notice, CompanyProfile $profile, Collection $vault, CarbonImmutable $today): array;

    /**
     * @return list<ComplianceIssue>
     */
    public function validateEconomicOffer(TenderNotice $notice, OfferInput $input): array;

    /**
     * Everything that must hold before the envelopes are lodged, except the envelope checklists themselves.
     *
     * @param  Collection<int, QualificationVaultDocument>  $vault
     * @return list<ComplianceIssue>
     */
    public function validateSubmission(TenderNotice $notice, TenderParticipation $participation, Collection $vault, CarbonImmutable $today): array;

    /**
     * ANAC tender contribution due by the bidder, null when the regime has none.
     */
    public function anacContribution(TenderNotice $notice): ?string;

    /**
     * Keys of the vault certifications that reduce the provisional bid bond under this regime.
     *
     * @return list<string>
     */
    public function bidBondReductionKeys(): array;

    /**
     * Default checklist of the three envelopes.
     *
     * @return array<string, list<array{key: string, required: bool, signature_required: bool}>>
     */
    public function envelopeTemplate(): array;

    /**
     * The references copied onto the contract and the project when the tender is awarded.
     *
     * @return array<string, string|null>
     */
    public function billingReferences(TenderNotice $notice, TenderParticipation $participation): array;

    /**
     * Whether the regime asks for the eDGUE (European Single Procurement Document).
     */
    public function supportsEdgue(): bool;
}
