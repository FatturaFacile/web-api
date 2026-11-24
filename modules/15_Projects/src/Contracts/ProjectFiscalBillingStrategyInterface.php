<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectSalCertificate;

/**
 * Jurisdiction-specific rules of project billing (Mod 081): the Italian strategy speaks FatturaPA (DatiSAL, DatiContratto
 * with CIG / CUP, the 0.50 % retention of public contracts) and OIC 23; the Swiss one speaks Akontorechnung /
 * Teilrechnung / Schlussabrechnung (SIA 118), Garantierückbehalt, QR-bill and Swiss GAAP FER 22.
 */
interface ProjectFiscalBillingStrategyInterface
{
    /** Two-letter jurisdiction the strategy serves (IT, CH). */
    public function jurisdiction(): string;

    /** Accounting standard of the period-end WIP valuation (OIC 23, FER 22). */
    public function accountingStandard(): string;

    /**
     * Guarantee retention percentage to apply: the contract's own, or the statutory one when the contract is silent.
     */
    public function effectiveRetentionPercent(ProjectBillingContract $contract): string;

    /**
     * Statutory retention percentage applied when the contract is silent, or null when the law fixes none.
     */
    public function statutoryRetentionPercent(ProjectBillingContract $contract): ?string;

    /**
     * Description of the progress line of the invoice of a SAL certificate.
     */
    public function progressLineDescription(ProjectBillingContract $contract, ProjectSalCertificate $sal, string $projectLabel): string;

    /**
     * Description of the advance deduction line of the invoice of a SAL certificate.
     */
    public function advanceLineDescription(ProjectSalCertificate $sal): string;

    /**
     * Invoice header additions: `notes` (printed wording) and `sdi_extra` (FatturaPA blocks, Italy only).
     *
     * @return array{notes: string, sdi_extra?: array<string, mixed>}
     */
    public function invoiceHeader(ProjectBillingContract $contract, ProjectSalCertificate $sal, string $projectLabel): array;

    /**
     * Amount the QR-bill / payment slip of the invoice must carry: the invoice total less the retention withheld.
     */
    public function payableAmount(string $invoiceTotalWithVat, string $retentionWithheld): string;

    /**
     * Expected loss still to provision on an onerous contract: the total loss on completion for the part of the work not
     * yet performed (the performed part is already expensed by the margin recognised proportionally).
     */
    public function expectedLossProvision(string $contractValue, string $estimatedTotalCost, string $completionRatio): string;
}
