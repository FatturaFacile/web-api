<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Compliance;

use Modules\IndustrialLaundry\Domain\Enums\FindingSeverity;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Support\Money;

/**
 * UNI EN 14065 RABC biocontamination control and the PPE wash limit.
 *
 * Batches: a disinfecting program must reach its target temperature for its hold time (thermal), its chemical dosing (chemical),
 * linen of a contaminated or protected zone needs a disinfecting program, and the microbial count of a tested batch stays under
 * the RABC ceiling. Garments: a PPE garment (EN ISO 20471 high visibility, EN ISO 11612 heat, EN 13034 chemical, ...) that reached
 * its certified wash cycles may not be issued again (D.Lgs. 81/08 art. 77, SUVA rules for Switzerland); category II and III PPE
 * are blocked, category I only warns, and an early warning rises from the configured share of the limit.
 */
final class En14065RabcAndPpeWashLimitStrategy implements IndustrialLaundryComplianceStrategyInterface
{
    public function key(): string
    {
        return 'en14065_rabc_ppe';
    }

    public function evaluateGarment(Garment $garment, int $projectedWashCycles): array
    {
        $limit = $garment->max_certified_wash_cycles_limit;

        if ($limit === null || $limit <= 0 || ! ($garment->article_type->isPpe() || $garment->ppe_certification_standard !== null)) {
            return [];
        }

        $params = ['epc' => $garment->epc_uid_rfid_hex, 'cycles' => $projectedWashCycles, 'limit' => $limit, 'standard' => $garment->ppe_certification_standard];

        if ($projectedWashCycles >= $limit) {
            $category = $garment->ppe_category;

            return [new ComplianceFinding('ppe_wash_limit_reached', $category !== null && $category < 2 ? FindingSeverity::Warning : FindingSeverity::Blocking, $params)];
        }

        $threshold = Money::mul((string) $limit, (string) config('industrial_laundry.ppe.warning_ratio', '0.9'));

        if (Money::compare((string) $projectedWashCycles, $threshold) >= 0) {
            return [new ComplianceFinding('ppe_wash_limit_near', FindingSeverity::Warning, $params + ['remaining' => $limit - $projectedWashCycles])];
        }

        return [];
    }

    public function evaluateWashBatch(WashBatchMeasurements $measurements): array
    {
        $program = $measurements->program;
        $findings = [];

        if ($measurements->riskZone->requiresDisinfection() && ! $program->disinfects) {
            $findings[] = new ComplianceFinding('rabc_program_not_disinfecting', FindingSeverity::Blocking, ['program' => $program->code, 'zone' => $measurements->riskZone->value]);
        }

        if ($program->disinfects) {
            $findings = [...$findings, ...$this->disinfectionFindings($measurements)];
        }

        $ceiling = (string) config('industrial_laundry.rabc.max_cfu_per_25cm2', '20');

        if ($measurements->cfuPer25Cm2 !== null && Money::compare($measurements->cfuPer25Cm2, $ceiling) > 0) {
            $findings[] = new ComplianceFinding('rabc_microbial_count_exceeded', FindingSeverity::Blocking, ['measured' => $measurements->cfuPer25Cm2, 'limit' => $ceiling]);
        }

        return $findings;
    }

    public function decorateBilling(BillingContext $context, BillingDecoration $decoration): BillingDecoration
    {
        return $decoration;
    }

    /**
     * @return list<ComplianceFinding>
     */
    private function disinfectionFindings(WashBatchMeasurements $measurements): array
    {
        $program = $measurements->program;
        $findings = [];

        if ($program->is_thermal) {
            if ($measurements->temperatureC === null || Money::compare($measurements->temperatureC, (string) $program->target_temperature_c) < 0) {
                $findings[] = new ComplianceFinding('rabc_temperature_below_target', FindingSeverity::Blocking, ['measured' => $measurements->temperatureC, 'target' => (string) $program->target_temperature_c]);
            }

            if ($measurements->holdMinutes === null || $measurements->holdMinutes < $program->min_hold_minutes) {
                $findings[] = new ComplianceFinding('rabc_hold_time_below_target', FindingSeverity::Blocking, ['measured' => $measurements->holdMinutes, 'target' => $program->min_hold_minutes]);
            }
        }

        if ($program->is_chemical && $program->min_chemical_dosing_ml_per_kg !== null
            && ($measurements->chemicalDosingMlPerKg === null || Money::compare($measurements->chemicalDosingMlPerKg, (string) $program->min_chemical_dosing_ml_per_kg) < 0)) {
            $findings[] = new ComplianceFinding('rabc_chemical_dosing_below_target', FindingSeverity::Blocking, ['measured' => $measurements->chemicalDosingMlPerKg, 'target' => (string) $program->min_chemical_dosing_ml_per_kg]);
        }

        return $findings;
    }
}
