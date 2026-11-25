<?php

declare(strict_types=1);

namespace Modules\Plm\Contracts;

use Modules\Manufacturing\Models\MfgBom;
use Modules\Plm\Models\PlmItemRevision;

/**
 * Strategy interface for PLM regulatory & technical construction dossier compliance
 * (CE, UKCA, Swiss OMacch, RoHS, REACH, Conflict Minerals, ISO 9001 / IATF 16949 / ISO 13485).
 */
interface IPlmRegulatoryComplianceStrategy
{
    public function strategyCode(): string;

    /**
     * @return list<string>
     */
    public function supportedRegulations(): array;

    /**
     * Evaluates technical dossier and multi-level BOM environmental compliance (RoHS, REACH, Conflict Minerals).
     *
     * @return array{
     *     strategy_code: string,
     *     regulations: list<string>,
     *     overall_compliant: bool,
     *     rohs_compliant: bool,
     *     reach_compliant: bool,
     *     conflict_minerals_compliant: bool,
     *     omacch_mra_ch_eu_compliant: bool,
     *     bom_components_checked: int,
     *     non_compliant_components: list<array<string, mixed>>,
     *     checklist: list<array<string, mixed>>,
     *     iso_audit_readiness: array<string, mixed>,
     *     evaluated_at: string
     * }
     */
    public function evaluateDossierAndBomCompliance(PlmItemRevision $revision, ?MfgBom $bom = null): array;

    /**
     * Verifies revision traceability and audit readiness for quality standards (ISO 9001, IATF 16949, ISO 13485).
     *
     * @param  list<string>  $standards
     * @return array<string, mixed>
     */
    public function verifyAuditReadiness(PlmItemRevision $revision, array $standards = ['ISO_9001', 'IATF_16949', 'ISO_13485']): array;
}
