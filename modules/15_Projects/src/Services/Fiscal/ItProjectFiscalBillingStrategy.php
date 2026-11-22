<?php

declare(strict_types=1);

namespace Modules\Projects\Services\Fiscal;

use App\Services\ItalianSdi\FatturaPaOptionalBlocks;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectSalCertificate;
use Modules\Projects\Support\Money;

/**
 * Italian billing rules: FatturaPA blocks of a SAL invoice (DatiSAL / RiferimentoFase, DatiContratto with CIG and CUP),
 * the 0.50 % guarantee retention of public contracts (art. 11, c. 6, D.Lgs. 36/2023: a contract with a CIG is a public
 * contract) and OIC 23 for the work in progress on order.
 */
final class ItProjectFiscalBillingStrategy extends AbstractProjectFiscalBillingStrategy
{
    public const string PublicProcurementRetentionPercent = '0.5';

    private const string Locale = 'it';

    public function jurisdiction(): string
    {
        return 'IT';
    }

    public function accountingStandard(): string
    {
        return 'OIC 23';
    }

    public function statutoryRetentionPercent(ProjectBillingContract $contract): ?string
    {
        return $contract->cig !== null && $contract->cig !== '' ? self::PublicProcurementRetentionPercent : null;
    }

    public function progressLineDescription(ProjectBillingContract $contract, ProjectSalCertificate $sal, string $projectLabel): string
    {
        return __('project_billing.labels.sal_line_it', [
            'number' => $sal->sal_number,
            'project' => $projectLabel,
            'from' => Money::round($sal->previous_completion_percent, 2),
            'to' => Money::round($sal->cumulative_completion_percent, 2),
        ], self::Locale);
    }

    public function advanceLineDescription(ProjectSalCertificate $sal): string
    {
        return __('project_billing.labels.advance_line_it', ['number' => $sal->sal_number], self::Locale);
    }

    public function invoiceHeader(ProjectBillingContract $contract, ProjectSalCertificate $sal, string $projectLabel): array
    {
        $notes = [__('project_billing.labels.sal_line_it', [
            'number' => $sal->sal_number,
            'project' => $projectLabel,
            'from' => Money::round($sal->previous_completion_percent, 2),
            'to' => Money::round($sal->cumulative_completion_percent, 2),
        ], self::Locale)];

        if (Money::isPositive($sal->guarantee_retention_withheld)) {
            $notes[] = __($contract->cig !== null && $contract->cig !== '' ? 'project_billing.labels.retention_note_it_public' : 'project_billing.labels.retention_note_it', [
                'percent' => Money::round($this->effectiveRetentionPercent($contract), 2),
                'amount' => Money::round($sal->guarantee_retention_withheld, 2),
            ], self::Locale);
        }

        $contract_block = array_filter([
            'IdDocumento' => mb_substr($contract->contract_reference ?? $sal->project_id, 0, 20),
            'Data' => $contract->contract_date?->toDateString(),
            'CodiceCUP' => $contract->cup,
            'CodiceCIG' => $contract->cig,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $general = ['DatiSAL' => [['RiferimentoFase' => $sal->sal_number]]];

        if ($contract->contract_reference !== null || $contract->cig !== null || $contract->cup !== null) {
            $general['DatiContratto'] = [$contract_block];
        }

        $text = implode(' ', $notes);

        return [
            'notes' => $text,
            'sdi_extra' => ['FatturaElettronicaBody' => [
                'DatiGenerali' => $general + ['DatiGeneraliDocumento' => ['Causale' => (new FatturaPaOptionalBlocks)->chunkCausale($text)]],
            ]],
        ];
    }
}
