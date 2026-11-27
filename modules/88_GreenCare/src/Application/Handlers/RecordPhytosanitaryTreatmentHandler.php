<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Handlers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\GreenCare\Application\Commands\RecordPhytosanitaryTreatmentCommand;
use Modules\GreenCare\Application\Services\OperatorLicenseGuard;
use Modules\GreenCare\Application\Services\StockIssuer;
use Modules\GreenCare\Domain\Compliance\ComplianceStrategyResolver;
use Modules\GreenCare\Domain\Exceptions\GreenCareException;
use Modules\GreenCare\Domain\Support\DoseCalculator;
use Modules\GreenCare\Models\GreenSpaceSite;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;

/**
 * Writes a treatment in the phytosanitary diary. The operator must hold an active, non-expired license accepted by the jurisdiction of the
 * site (PER / PNRP / OPF in Switzerland, patentino PAN in Italy) on the treatment date; the registration number of the product must have the
 * shape of the authority; the product used leaves the phytosanitary warehouse. The entry is immutable and written in one transaction with the stock movement.
 */
final class RecordPhytosanitaryTreatmentHandler
{
    public function __construct(
        private readonly ComplianceStrategyResolver $strategies,
        private readonly OperatorLicenseGuard $licenses,
        private readonly StockIssuer $stock,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @throws GreenCareException operator_license_missing, operator_license_expired, registration_number_invalid, warehouse_required
     */
    public function handle(RecordPhytosanitaryTreatmentCommand $command): PhytosanitaryTreatmentLog
    {
        $site = GreenSpaceSite::query()->findOrFail($command->siteId);
        $strategy = $this->strategies->for($site->jurisdiction);

        if (! $strategy->isValidRegistrationNumber($command->registrationNumber)) {
            throw GreenCareException::because('registration_number_invalid', ['hint' => $strategy->registrationNumberHint(), 'jurisdiction' => $strategy->jurisdiction()]);
        }

        $license = $this->licenses->assertValid($command->operatorId, $command->treatmentDate, $strategy);

        return DB::transaction(function () use ($command, $site, $license): PhytosanitaryTreatmentLog {
            $log = new PhytosanitaryTreatmentLog([
                'tenant_id' => (string) $this->tenants->getTenantId(),
                'site_id' => $site->id,
                'route_stop_id' => $command->routeStopId,
                'treatment_date' => $command->treatmentDate->toDateString(),
                'jurisdiction' => $site->jurisdiction,
                'target_pest_or_weed' => $command->targetPestOrWeed,
                'treated_crop_or_surface' => $command->treatedCropOrSurface,
                'treated_area_sqm' => $command->treatedAreaSqm,
                'commercial_product_name' => $command->commercialProductName,
                'registration_number' => strtoupper(trim($command->registrationNumber)),
                'active_ingredient' => $command->activeIngredient,
                'dosage_used' => $command->dosageUsed,
                'dosage_unit' => $command->dosageUnit,
                'water_volume_liters' => $command->waterVolumeLiters,
                'warehouse_product_id' => $command->warehouseProductId,
                'operator_id' => $command->operatorId,
                'operator_license_id' => $license->id,
                're_entry_interval_hours' => $command->reEntryIntervalHours,
                'weather_conditions_at_application' => $command->weatherConditions,
                'notes' => $command->notes,
            ]);

            if ($command->warehouseProductId !== null) {
                $quantity = DoseCalculator::productQuantity($command->dosageUnit, $command->dosageUsed, $command->treatedAreaSqm, $command->waterVolumeLiters);

                if ($quantity > 0) {
                    $issued = $this->stock->issue($command->warehouseProductId, $quantity, $command->warehouseId, $log, 'Phytosanitary treatment '.$command->treatmentDate->toDateString());
                    $log->warehouse_id = $issued['warehouse_id'];
                    $log->quantity_deducted = (string) $quantity;
                }
            }

            $log->save();

            return $log->refresh();
        });
    }
}
