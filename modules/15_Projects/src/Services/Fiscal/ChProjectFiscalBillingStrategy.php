<?php

declare(strict_types=1);

namespace Modules\Projects\Services\Fiscal;

use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectSalCertificate;
use Modules\Projects\Support\Money;

/**
 * Swiss billing rules: partial invoices (Akontorechnung / Teilrechnung) and the final settlement (Schlussabrechnung) of
 * a construction-type contract along SIA 118, the contractual guarantee retention (Garantierückbehalt, typically 5 % or
 * 10 %, no statutory default) deducted from the payment, the QR-bill for the amount actually payable, and Swiss GAAP
 * FER 22 for the long-term contracts. The wording follows the locale of the request (de-CH, fr-CH, it-CH, en).
 */
final class ChProjectFiscalBillingStrategy extends AbstractProjectFiscalBillingStrategy
{
    public function jurisdiction(): string
    {
        return 'CH';
    }

    public function accountingStandard(): string
    {
        return 'Swiss GAAP FER 22';
    }

    public function statutoryRetentionPercent(ProjectBillingContract $contract): ?string
    {
        return null;
    }

    public function progressLineDescription(ProjectBillingContract $contract, ProjectSalCertificate $sal, string $projectLabel): string
    {
        return __($sal->is_final ? 'project_billing.labels.sal_line_ch_final' : 'project_billing.labels.sal_line_ch_partial', $this->parameters($sal, $projectLabel));
    }

    public function advanceLineDescription(ProjectSalCertificate $sal): string
    {
        return __('project_billing.labels.advance_line_ch', ['number' => $sal->sal_number]);
    }

    public function invoiceHeader(ProjectBillingContract $contract, ProjectSalCertificate $sal, string $projectLabel): array
    {
        $notes = [$this->progressLineDescription($contract, $sal, $projectLabel)];

        if (Money::isPositive($sal->guarantee_retention_withheld)) {
            $notes[] = __('project_billing.labels.retention_note_ch', [
                'percent' => Money::round($this->effectiveRetentionPercent($contract), 2),
                'amount' => Money::round($sal->guarantee_retention_withheld, 2),
            ]);
        }

        if ($contract->contract_reference !== null) {
            $notes[] = __('project_billing.labels.contract_reference_ch', ['reference' => $contract->contract_reference]);
        }

        return ['notes' => implode(' ', $notes)];
    }

    /**
     * @return array<string, string|int>
     */
    private function parameters(ProjectSalCertificate $sal, string $projectLabel): array
    {
        return [
            'number' => $sal->sal_number,
            'project' => $projectLabel,
            'from' => Money::round($sal->previous_completion_percent, 2),
            'to' => Money::round($sal->cumulative_completion_percent, 2),
        ];
    }
}
