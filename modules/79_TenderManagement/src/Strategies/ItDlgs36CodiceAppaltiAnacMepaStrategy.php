<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Strategies;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Domain\Enums\ProcedureType;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Domain\Support\SoaClassification;
use Modules\TenderManagement\Domain\ValueObjects\CompanyProfile;
use Modules\TenderManagement\Domain\ValueObjects\ComplianceIssue;
use Modules\TenderManagement\Domain\ValueObjects\CriterionResult;
use Modules\TenderManagement\Domain\ValueObjects\OfferInput;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Models\TenderParticipation;

/**
 * Italian public tenders under D.Lgs. 36/2023 (ANAC, MePA): SOA attestations with their triennial verification, DURC, FVOE 2.0
 * synchronisation, the eDGUE, the ANAC tender contribution, the separate indication of the company labour and safety costs
 * (Art. 108 co. 9), the provisional guarantee and the traceability of financial flows (L. 136/2010: CIG, CUP, RUP and the
 * dedicated account, carried to the FatturaPA e-invoices).
 */
final class ItDlgs36CodiceAppaltiAnacMepaStrategy extends AbstractTenderStrategy
{
    public function supports(Jurisdiction $jurisdiction): bool
    {
        return $jurisdiction === Jurisdiction::ItalianAnac;
    }

    public function regime(): string
    {
        return 'IT';
    }

    public function supportsEdgue(): bool
    {
        return true;
    }

    public function bidBondReductionKeys(): array
    {
        return array_keys((array) config('tender_management.bid_bond.reductions', []));
    }

    public function validateNotice(TenderNotice $notice): array
    {
        $issues = [];

        foreach ([['cig_code_it', 'cig_missing'], ['cup_code_it', 'cup_missing'], ['rup_name', 'rup_missing'], ['dedicated_bank_account_it', 'dedicated_account_missing']] as [$field, $code]) {
            if (blank($notice->{$field})) {
                $issues[] = ComplianceIssue::warning($code);
            }
        }

        return $issues;
    }

    public function validateVaultDocument(QualificationVaultDocument $document, CarbonImmutable $today): array
    {
        $issues = parent::validateVaultDocument($document, $today);

        if ($document->doc_type === VaultDocType::ItSoa) {
            if (($document->soa_categories_classifications ?? []) === []) {
                $issues[] = ComplianceIssue::blocking('soa_missing');
            }

            $check = $document->intermediate_triennial_check_date;
            if ($check !== null && CarbonImmutable::parse($check->toDateString())->lessThanOrEqualTo($today)) {
                $issues[] = ComplianceIssue::warning('soa_intermediate_check_due');
            }
        }

        if (in_array($document->doc_type, [VaultDocType::ItSoa, VaultDocType::ItDurc], true) && ! $document->fvoe20_synced) {
            $issues[] = ComplianceIssue::info('fvoe_not_synced', ['type' => $document->doc_type->value]);
        }

        return $issues;
    }

    public function evaluateRequirements(TenderNotice $notice, CompanyProfile $profile, Collection $vault, CarbonImmutable $today): array
    {
        $rows = parent::evaluateRequirements($notice, $profile, $vault, $today);
        $held = $this->heldSoa($vault, $today);

        foreach ($notice->required_soa_categories ?? [] as $requirement) {
            $category = strtoupper($requirement['category']);
            $required = $requirement['classification'];
            $available = $held[$category] ?? null;
            $met = $available !== null && SoaClassification::covers($available, $required);
            $rows[] = new CriterionResult('soa:'.$category, CriterionResult::Qualification, $required, $available, $met, $met ? null : $required, 20);
        }

        $durc = $this->usable($vault, VaultDocType::ItDurc, $today);
        $rows[] = new CriterionResult('document:'.VaultDocType::ItDurc->value, CriterionResult::Compliance, VaultDocType::ItDurc->value, $durc?->document_number, $durc !== null, $durc === null ? VaultDocType::ItDurc->value : null, 10, $durc?->id);

        $synced = $vault->filter(fn (QualificationVaultDocument $document): bool => in_array($document->doc_type, [VaultDocType::ItSoa, VaultDocType::ItDurc], true) && $this->status->isUsable($document, $today));
        $unsynced = $synced->reject(fn (QualificationVaultDocument $document): bool => $document->fvoe20_synced);
        $rows[] = new CriterionResult('fvoe_sync', CriterionResult::Compliance, null, $synced->count() - $unsynced->count(), $synced->isNotEmpty() && $unsynced->isEmpty(), $unsynced->count() > 0 ? $unsynced->count() : null, 5);

        return $rows;
    }

    public function validateEconomicOffer(TenderNotice $notice, OfferInput $input): array
    {
        $issues = [];

        if ($input->companySafetyCosts === null || ! Dec::isPositive($input->companySafetyCosts)) {
            $issues[] = ComplianceIssue::blocking('art108_safety_costs_missing', [], 'economic');
        }

        if ($input->companyLaborCosts === null || ! Dec::isPositive($input->companyLaborCosts)) {
            $issues[] = ComplianceIssue::blocking('art108_labor_costs_missing', [], 'economic');
        } elseif (Dec::isPositive($notice->estimated_labor_cost_amount) && Dec::cmp($input->companyLaborCosts, $notice->estimated_labor_cost_amount) < 0) {
            $issues[] = ComplianceIssue::warning('art108_labor_costs_below_estimate', [], 'economic');
        }

        return $issues;
    }

    public function validateSubmission(TenderNotice $notice, TenderParticipation $participation, Collection $vault, CarbonImmutable $today): array
    {
        $issues = $this->deadlineIssues($notice, $today);

        foreach ($this->validateNotice($notice) as $issue) {
            $issues[] = $issue;
        }

        $held = $this->heldSoa($vault, $today);
        foreach ($notice->required_soa_categories ?? [] as $requirement) {
            $category = strtoupper($requirement['category']);
            $required = $requirement['classification'];

            if (! isset($held[$category])) {
                $issues[] = ComplianceIssue::blocking('soa_category_missing', ['category' => $category], 'administrative');
            } elseif (! SoaClassification::covers($held[$category], $required)) {
                $issues[] = ComplianceIssue::blocking('soa_classification_insufficient', ['category' => $category, 'required' => $required, 'available' => $held[$category]], 'administrative');
            }
        }

        foreach ($vault->filter(fn (QualificationVaultDocument $document): bool => $document->doc_type === VaultDocType::ItSoa && $this->status->isUsable($document, $today)) as $soa) {
            if ($soa->intermediate_triennial_check_date !== null && CarbonImmutable::parse($soa->intermediate_triennial_check_date->toDateString())->lessThanOrEqualTo($today)) {
                $issues[] = ComplianceIssue::warning('soa_intermediate_check_due', [], 'administrative');
            }
        }

        foreach ($this->requireDocument($vault, VaultDocType::ItDurc, $today, 'durc_missing', 'durc_expired') as $issue) {
            $issues[] = $issue;
        }

        if ($vault->contains(fn (QualificationVaultDocument $document): bool => in_array($document->doc_type, [VaultDocType::ItSoa, VaultDocType::ItDurc], true) && ! $document->fvoe20_synced)) {
            $issues[] = ComplianceIssue::warning('fvoe_not_synced', [], 'administrative');
        }

        if ($participation->edgue_data === null) {
            $issues[] = ComplianceIssue::blocking('edgue_missing', [], 'administrative');
        }

        if ($notice->procedure_type !== ProcedureType::DirectAward && $participation->provisional_bid_bond_id === null) {
            $issues[] = ComplianceIssue::blocking('bid_bond_missing', [], 'administrative');
        }

        $contribution = $this->anacContribution($notice);
        if ($contribution !== null && Dec::isPositive($contribution) && blank($participation->anac_contribution_receipt_id)) {
            $issues[] = ComplianceIssue::blocking('anac_contribution_receipt_missing', [], 'administrative');
        }

        if ($participation->company_safety_costs_declared === null || ! Dec::isPositive($participation->company_safety_costs_declared)) {
            $issues[] = ComplianceIssue::blocking('art108_safety_costs_missing', [], 'economic');
        }

        if ($participation->company_labor_costs_declared === null || ! Dec::isPositive($participation->company_labor_costs_declared)) {
            $issues[] = ComplianceIssue::blocking('art108_labor_costs_missing', [], 'economic');
        }

        return $issues;
    }

    public function anacContribution(TenderNotice $notice): ?string
    {
        foreach ((array) config('tender_management.anac_contribution.brackets', []) as $bracket) {
            if ($bracket['below'] === null || Dec::cmp($notice->base_auction_amount, $bracket['below']) < 0) {
                return Dec::money($bracket['fee']);
            }
        }

        return null;
    }

    public function envelopeTemplate(): array
    {
        return [
            'administrative' => $this->checklist([
                ['edgue', true, true], ['soa_attestation', true, false], ['durc', true, false], ['visura_camerale', true, false],
                ['casellario_giudiziale', true, false], ['bid_bond', true, true], ['anac_contribution_receipt', true, false],
                ['pass_oe', false, false], ['rti_mandate', false, true], ['avvalimento_contract', false, true],
                ['financial_traceability_declaration', true, true], ['integrity_pact', false, true],
            ]),
            'technical' => $this->checklist([
                ['technical_offer', true, true], ['work_plan', true, false], ['quality_plan', false, false],
                ['safety_plan', false, false], ['team_curricula', false, false],
            ]),
            'economic' => $this->checklist([
                ['economic_offer', true, true], ['labor_safety_costs_declaration', true, true], ['cost_justification', false, false],
            ]),
        ];
    }

    public function billingReferences(TenderNotice $notice, TenderParticipation $participation): array
    {
        return [
            'cig' => $notice->cig_code_it,
            'cup' => $notice->cup_code_it,
            'rup' => $notice->rup_name,
            'dedicated_bank_account' => $notice->dedicated_bank_account_it,
            'simap_project_number' => null,
            'sdi_reference_block' => 'contract',
        ];
    }

    /**
     * Highest classification held per SOA category over the usable attestations.
     *
     * @param  Collection<int, QualificationVaultDocument>  $vault
     * @return array<string, string>
     */
    private function heldSoa(Collection $vault, CarbonImmutable $today): array
    {
        $held = [];

        foreach ($vault as $document) {
            if ($document->doc_type !== VaultDocType::ItSoa || ! $this->status->isUsable($document, $today)) {
                continue;
            }

            foreach ($document->soa_categories_classifications ?? [] as $row) {
                $category = strtoupper($row['category']);
                $classification = $row['classification'];

                $rank = SoaClassification::rank($classification);

                if ($category === '' || $rank === null) {
                    continue;
                }

                if (! isset($held[$category]) || $rank > (SoaClassification::rank($held[$category]) ?? -1)) {
                    $held[$category] = $classification;
                }
            }
        }

        return $held;
    }
}
