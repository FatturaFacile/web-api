<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Strategies;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Domain\ValueObjects\CompanyProfile;
use Modules\TenderManagement\Domain\ValueObjects\ComplianceIssue;
use Modules\TenderManagement\Domain\ValueObjects\CriterionResult;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Models\TenderParticipation;

/**
 * Swiss public tenders published on simap.ch under the federal procurement law (LAPub / BöB) and the intercantonal agreement
 * (CIAP / IVöB): open, selective, invitation and direct award procedures. The bidder proves the debt-enforcement extract (not
 * older than three months), the AVS/AI/IPG, SUVA, LPP and AFC VAT certificates, the respect of the collective labour
 * agreement and, for federal tenders, the Logib equal-pay analysis.
 */
final class ChSimapLapubCiapStrategy extends AbstractTenderStrategy
{
    /** Proofs every Swiss bidder has to hold. */
    private const array Mandatory = [VaultDocType::ChBetreibungsauszug, VaultDocType::ChAvs, VaultDocType::ChSuva, VaultDocType::ChLpp, VaultDocType::ChAfcVat];

    public function supports(Jurisdiction $jurisdiction): bool
    {
        return $jurisdiction === Jurisdiction::SwissSimap;
    }

    public function regime(): string
    {
        return 'CH';
    }

    public function validateNotice(TenderNotice $notice): array
    {
        return blank($notice->simap_project_number) ? [ComplianceIssue::warning('simap_number_missing')] : [];
    }

    public function validateVaultDocument(QualificationVaultDocument $document, CarbonImmutable $today): array
    {
        $issues = parent::validateVaultDocument($document, $today);

        if ($document->doc_type === VaultDocType::ChBetreibungsauszug && $document->issue_date !== null) {
            $limit = (int) config('tender_management.ch.betreibungsauszug_max_age_months', 3);
            $oldest = $today->subMonthsNoOverflow($limit)->startOfDay();

            if (CarbonImmutable::parse($document->issue_date->toDateString())->lessThan($oldest)) {
                $issues = array_values(array_filter($issues, static fn (ComplianceIssue $issue): bool => $issue->code !== 'document_expired'));
                $issues[] = ComplianceIssue::blocking('betreibungsauszug_too_old', ['months' => $limit]);
            }
        }

        if ($document->doc_type === VaultDocType::ChLogib && $document->numeric_value !== null) {
            $limit = (string) config('tender_management.ch.logib_max_deviation_percent', '5.00');

            if (Dec::cmp($document->numeric_value, $limit) > 0) {
                $issues[] = ComplianceIssue::blocking('logib_deviation_exceeded', ['value' => $document->numeric_value, 'limit' => $limit]);
            }
        }

        return $issues;
    }

    public function evaluateRequirements(TenderNotice $notice, CompanyProfile $profile, Collection $vault, CarbonImmutable $today): array
    {
        $rows = parent::evaluateRequirements($notice, $profile, $vault, $today);
        $listed = array_map('strval', $notice->required_certifications ?? []);

        foreach (self::Mandatory as $type) {
            $document = $this->usable($vault, $type, $today);
            $rows[] = new CriterionResult('document:'.$type->value, CriterionResult::Compliance, $type->value, $document?->document_number, $document !== null, $document === null ? $type->value : null, 10, $document?->id);
        }

        foreach ([VaultDocType::ChCcl, VaultDocType::ChLogib] as $type) {
            if (! in_array($type->value, $listed, true)) {
                continue;
            }

            $document = $this->usable($vault, $type, $today);
            $rows[] = new CriterionResult('document:'.$type->value, CriterionResult::Compliance, $type->value, $document?->document_number, $document !== null, $document === null ? $type->value : null, 10, $document?->id);
        }

        return $rows;
    }

    public function validateSubmission(TenderNotice $notice, TenderParticipation $participation, Collection $vault, CarbonImmutable $today): array
    {
        $issues = [...$this->deadlineIssues($notice, $today), ...$this->validateNotice($notice)];
        $listed = array_map('strval', $notice->required_certifications ?? []);

        foreach (self::Mandatory as $type) {
            $present = $vault->contains(fn (QualificationVaultDocument $document): bool => $document->doc_type === $type);

            if ($type === VaultDocType::ChBetreibungsauszug) {
                if (! $present) {
                    $issues[] = ComplianceIssue::blocking('betreibungsauszug_missing', [], 'administrative');

                    continue;
                }

                $fresh = $this->usable($vault, $type, $today);
                $limit = (int) config('tender_management.ch.betreibungsauszug_max_age_months', 3);

                if ($fresh === null) {
                    $issues[] = ComplianceIssue::blocking('betreibungsauszug_too_old', ['months' => $limit], 'administrative');
                }

                continue;
            }

            foreach ($this->requireDocument($vault, $type, $today) as $issue) {
                $issues[] = $issue;
            }
        }

        foreach ([VaultDocType::ChCcl, VaultDocType::ChLogib] as $type) {
            if (in_array($type->value, $listed, true)) {
                foreach ($this->requireDocument($vault, $type, $today) as $issue) {
                    $issues[] = $issue;
                }
            }
        }

        $logib = $this->usable($vault, VaultDocType::ChLogib, $today);
        $limit = (string) config('tender_management.ch.logib_max_deviation_percent', '5.00');

        if ($logib?->numeric_value !== null && Dec::cmp($logib->numeric_value, $limit) > 0) {
            $issues[] = ComplianceIssue::blocking('logib_deviation_exceeded', ['value' => $logib->numeric_value, 'limit' => $limit], 'administrative');
        }

        return $issues;
    }

    public function envelopeTemplate(): array
    {
        return [
            'administrative' => $this->checklist([
                ['bid_form', true, true], ['betreibungsauszug', true, false], ['avs_certificate', true, false], ['suva_certificate', true, false],
                ['lpp_certificate', true, false], ['afc_vat_certificate', true, false], ['ccl_declaration', false, true],
                ['logib_analysis', false, false], ['subcontractor_declaration', false, true], ['consortium_declaration', false, true],
            ]),
            'technical' => $this->checklist([
                ['methodology', true, false], ['references', true, false], ['team_curricula', false, false], ['schedule', false, false],
            ]),
            'economic' => $this->checklist([
                ['price_sheet', true, true], ['price_breakdown', false, false],
            ]),
        ];
    }

    public function billingReferences(TenderNotice $notice, TenderParticipation $participation): array
    {
        return [
            'cig' => null,
            'cup' => null,
            'rup' => null,
            'dedicated_bank_account' => null,
            'simap_project_number' => $notice->simap_project_number,
            'sdi_reference_block' => null,
        ];
    }
}
