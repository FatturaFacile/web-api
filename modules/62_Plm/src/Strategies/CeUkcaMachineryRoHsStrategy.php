<?php

declare(strict_types=1);

namespace Modules\Plm\Strategies;

use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Plm\Contracts\IPlmRegulatoryComplianceStrategy;
use Modules\Plm\Models\PlmItemRevision;
use Modules\Plm\Models\PlmTechnicalDossier;

/**
 * Regulatory compliance strategy for EU Machinery Directive 2006/42/EC, EU Machinery Regulation 2023/1230,
 * Swiss OMacch (RS 819.14 / CH-EU MRA), UKCA, RoHS, REACH, Conflict Minerals, and ISO 9001 / IATF 16949 / ISO 13485.
 */
final class CeUkcaMachineryRoHsStrategy implements IPlmRegulatoryComplianceStrategy
{
    public const string CODE = 'ce_ukca_machinery_rohs';

    public function strategyCode(): string
    {
        return self::CODE;
    }

    /**
     * @return list<string>
     */
    public function supportedRegulations(): array
    {
        return [
            'EU_MACHINERY_DIRECTIVE_2006_42_EC',
            'EU_MACHINERY_REGULATION_2023_1230',
            'CH_OMACCH_RS_819_14_MRA',
            'UKCA_SUPPLY_OF_MACHINERY_2008',
            'ROHS_2011_65_EU_2015_863',
            'REACH_EC_1907_2006',
            'CONFLICT_MINERALS_3TG_EU_2017_821',
        ];
    }

    public function evaluateDossierAndBomCompliance(PlmItemRevision $revision, ?MfgBom $bom = null): array
    {
        $resolvedBom = $bom ?? ($revision->mbom_id !== null ? MfgBom::query()->where('tenant_id', $revision->tenant_id)->find($revision->mbom_id) : null);
        $checked = 0;
        $nonCompliant = [];
        $allRohsReach = (bool) $revision->rohs_and_reach_compliant;
        $allConflictMinerals = (bool) $revision->conflict_minerals_compliant;

        if ($resolvedBom instanceof MfgBom) {
            $visited = [(string) $resolvedBom->mfg_item_id => true];
            $this->collectBomCompliance($resolvedBom, $visited, $checked, $nonCompliant, $allRohsReach, $allConflictMinerals);
        }

        $dossier = PlmTechnicalDossier::query()
            ->where('tenant_id', $revision->tenant_id)
            ->where('revision_id', $revision->id)
            ->first();

        $checklist = $dossier->checklist_items_json ?? $this->defaultChecklist($revision, $allRohsReach, $allConflictMinerals);
        $omacchCompliant = $dossier !== null ? (bool) $dossier->omacch_mra_ch_eu_compliant : true;
        $isoReadiness = $this->verifyAuditReadiness($revision);

        $checklistComplete = true;
        foreach ($checklist as $entry) {
            if (($entry['status'] ?? 'pending') !== 'completed') {
                $checklistComplete = false;
                break;
            }
        }

        $overallCompliant = $allRohsReach && $allConflictMinerals && $omacchCompliant && $nonCompliant === [] && $checklistComplete;

        return [
            'strategy_code' => self::CODE,
            'regulations' => $this->supportedRegulations(),
            'overall_compliant' => $overallCompliant,
            'rohs_compliant' => $allRohsReach,
            'reach_compliant' => $allRohsReach,
            'conflict_minerals_compliant' => $allConflictMinerals,
            'omacch_mra_ch_eu_compliant' => $omacchCompliant,
            'bom_components_checked' => $checked,
            'non_compliant_components' => $nonCompliant,
            'checklist' => $checklist,
            'iso_audit_readiness' => $isoReadiness,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $standards
     * @return array<string, mixed>
     */
    public function verifyAuditReadiness(PlmItemRevision $revision, array $standards = ['ISO_9001', 'IATF_16949', 'ISO_13485']): array
    {
        $hasLineage = $revision->parent_revision_id !== null || $revision->revision !== '';
        $hasCadFiles = is_array($revision->cad_drawing_pdf_and_step_file_ids) && $revision->cad_drawing_pdf_and_step_file_ids !== [];
        $hasTechnicalFile = is_string($revision->technical_file_ref) && trim($revision->technical_file_ref) !== '';
        $hasAuditTrail = is_array($revision->audit_trail_json) && $revision->audit_trail_json !== [];

        $results = [];
        foreach ($standards as $standard) {
            $ready = match ($standard) {
                'ISO_9001' => $hasLineage && ($hasCadFiles || $hasTechnicalFile),
                'IATF_16949' => $hasLineage && $hasCadFiles && $hasTechnicalFile,
                'ISO_13485' => $hasLineage && $hasCadFiles && $hasTechnicalFile && $revision->rohs_and_reach_compliant,
                default => $hasLineage,
            };

            $results[$standard] = [
                'standard' => $standard,
                'ready' => $ready,
                'checks' => [
                    'revision_lineage' => $hasLineage,
                    'cad_drawings_attached' => $hasCadFiles,
                    'technical_file_ref' => $hasTechnicalFile,
                    'audit_trail_recorded' => $hasAuditTrail || $revision->change_order_id !== null,
                ],
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, bool>  $visited
     * @param  list<array<string, mixed>>  $nonCompliant
     */
    private function collectBomCompliance(MfgBom $bom, array &$visited, int &$checked, array &$nonCompliant, bool &$allRohsReach, bool &$allConflictMinerals): void
    {
        $components = MfgBomComponent::query()->with('componentItem')->where('bom_id', $bom->id)->get();

        foreach ($components as $component) {
            $item = $component->componentItem;
            if (! $item instanceof MfgItem) {
                continue;
            }

            $checked++;

            $latestRev = PlmItemRevision::query()
                ->where('tenant_id', $bom->tenant_id)
                ->where('mfg_item_id', $item->id)
                ->orderByRaw("CASE WHEN status = 'released' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->first();

            $rohsReachOk = $latestRev instanceof PlmItemRevision ? (bool) $latestRev->rohs_and_reach_compliant : true;
            $conflictOk = $latestRev instanceof PlmItemRevision ? (bool) $latestRev->conflict_minerals_compliant : true;

            if (! $rohsReachOk || ! $conflictOk) {
                $allRohsReach = $allRohsReach && $rohsReachOk;
                $allConflictMinerals = $allConflictMinerals && $conflictOk;
                $nonCompliant[] = [
                    'mfg_item_id' => (string) $item->id,
                    'item_code' => $item->item_code,
                    'item_name' => $item->name,
                    'revision' => $latestRev->revision,
                    'rohs_and_reach_compliant' => $rohsReachOk,
                    'conflict_minerals_compliant' => $conflictOk,
                ];
            }

            if (! isset($visited[(string) $item->id])) {
                $visited[(string) $item->id] = true;
                $subBom = MfgBom::query()
                    ->where('tenant_id', $bom->tenant_id)
                    ->where('mfg_item_id', $item->id)
                    ->whereIn('status', MfgBom::EffectiveStatuses)
                    ->orderByDesc('version')
                    ->first();

                if ($subBom instanceof MfgBom) {
                    $this->collectBomCompliance($subBom, $visited, $checked, $nonCompliant, $allRohsReach, $allConflictMinerals);
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultChecklist(PlmItemRevision $revision, bool $rohsReachOk, bool $conflictOk): array
    {
        $hasDrawings = is_array($revision->cad_drawing_pdf_and_step_file_ids) && $revision->cad_drawing_pdf_and_step_file_ids !== [];
        $hasTechFile = is_string($revision->technical_file_ref) && trim($revision->technical_file_ref) !== '';

        return [
            ['code' => 'RISK_ASSESSMENT_ISO_12100', 'regulation' => '2006/42/EC + EU 2023/1230', 'status' => $hasTechFile ? 'completed' : 'pending'],
            ['code' => 'ESSENTIAL_HEALTH_SAFETY_REQ', 'regulation' => 'OMacch RS 819.14 / MRA CH-EU', 'status' => $hasTechFile ? 'completed' : 'pending'],
            ['code' => 'CAD_CONSTRUCTION_DRAWINGS', 'regulation' => '2006/42/EC Annex VII', 'status' => $hasDrawings ? 'completed' : 'pending'],
            ['code' => 'ROHS_REACH_DECLARATION', 'regulation' => '2011/65/EU + EC 1907/2006', 'status' => $rohsReachOk ? 'completed' : 'pending'],
            ['code' => 'CONFLICT_MINERALS_3TG', 'regulation' => 'EU 2017/821', 'status' => $conflictOk ? 'completed' : 'pending'],
            ['code' => 'EU_CH_DECLARATION_OF_CONFORMITY', 'regulation' => 'CE / UKCA / OMacch', 'status' => ($hasTechFile && $rohsReachOk) ? 'completed' : 'pending'],
        ];
    }
}
