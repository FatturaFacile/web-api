<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\BondedWarehouses\Application\Commands\RunAeoReconciliationAndComplianceCheckCommand;
use Modules\BondedWarehouses\Domain\Enums\AeoStatus;
use Modules\BondedWarehouses\Domain\Enums\AuditType;
use Modules\BondedWarehouses\Domain\Enums\CustomsStatus;
use Modules\BondedWarehouses\Domain\Enums\DiscrepancyStatus;
use Modules\BondedWarehouses\Domain\Enums\RegimeType;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Domain\Support\ComplianceClassifier;
use Modules\BondedWarehouses\Events\AeoReconciliationCompleted;
use Modules\BondedWarehouses\Models\AeoComplianceAudit;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;
use Modules\Inventory\Services\ProductStockLedger;

/**
 * AEO internal control (Art. 39 CDU): compares the WMS physical balance of each product (Inventory) with the remaining quantities of the MRN lots,
 * checks the guarantee utilization against the 80 % / 95 % thresholds, flags usual handling left open and logs the AEO security checks of
 * carriers and suppliers. Every finding is appended to the audit log with a stable code in the findings column.
 */
final class RunAeoReconciliationAndComplianceCheckHandler
{
    /** A usual handling open for longer than this many days is reported. */
    private const int USUAL_HANDLING_MAX_DAYS = 30;

    public function __construct(private readonly ProductStockLedger $stock) {}

    /**
     * @return array{audits: list<AeoComplianceAudit>, critical: int, minor: int, matched: int, cgu_utilization_percent: float}
     *
     * @throws BondedWarehouseException authorization_not_found, regime_not_customs_warehouse
     */
    public function handle(RunAeoReconciliationAndComplianceCheckCommand $command): array
    {
        return DB::transaction(function () use ($command): array {
            $authorization = CustomsWarehouseAuthorization::query()->find($command->authorizationId)
                ?? throw BondedWarehouseException::because('authorization_not_found', [], 404);

            if ($authorization->regime_type === RegimeType::VatDepotArt50Bis) {
                throw BondedWarehouseException::because('regime_not_customs_warehouse');
            }

            $date = Carbon::parse($command->auditDate);
            $audits = [
                ...$this->physicalReconciliation($authorization, $date, $command->userId),
                $this->cguThreshold($authorization, $date, $command->userId),
                ...$this->usualHandling($authorization, $date, $command->userId),
                ...$this->partnerChecks($authorization, $date, $command),
            ];

            $count = static fn (DiscrepancyStatus $status): int => count(array_filter($audits, static fn (AeoComplianceAudit $audit): bool => $audit->discrepancy_status === $status));
            $critical = $count(DiscrepancyStatus::CriticalAlertADMUDSC);

            event(new AeoReconciliationCompleted((string) $authorization->id, count($audits), $critical));

            return [
                'audits' => $audits,
                'critical' => $critical,
                'minor' => $count(DiscrepancyStatus::MinorVariance),
                'matched' => $count(DiscrepancyStatus::Matched),
                'cgu_utilization_percent' => $authorization->utilizationPercent(),
            ];
        });
    }

    /**
     * @return list<AeoComplianceAudit>
     */
    private function physicalReconciliation(CustomsWarehouseAuthorization $authorization, Carbon $date, ?string $userId): array
    {
        $ledger = BondedMrnLot::query()
            ->where('authorization_id', $authorization->id)
            ->where('remaining_quantity', '>', 0)
            ->selectRaw('product_id, SUM(remaining_quantity) AS remaining')
            ->groupBy('product_id')
            ->toBase()
            ->get()
            ->pluck('remaining', 'product_id');

        $physical = $this->stock->levels((string) $authorization->warehouse_id)->keyBy('product_id');
        $products = $ledger->keys()->merge($physical->keys())->unique()->values();
        $audits = [];

        foreach ($products as $productId) {
            $bonded = round((float) ($ledger[$productId] ?? 0), 4);
            $onHand = round((float) ($physical[$productId]['on_hand'] ?? 0), 4);

            // Stock of the warehouse that is not under customs supervision is no finding: only a shortfall or an unbooked surplus of bonded goods is.
            if ($bonded <= 0.0 && $onHand > 0.0 && ! $ledger->has($productId)) {
                continue;
            }

            $status = ComplianceClassifier::variance($onHand, $bonded);
            $audits[] = $this->record($authorization, AuditType::PhysicalBookReconciliation, $date, $userId, [
                'product_id' => (string) $productId,
                'wms_physical_quantity' => $onHand,
                'customs_ledger_quantity' => $bonded,
                'discrepancy_quantity' => round($onHand - $bonded, 4),
                'discrepancy_status' => $status,
                'findings' => $status === DiscrepancyStatus::Matched ? 'book_matches_physical' : ($onHand < $bonded ? 'physical_shortfall' : 'physical_surplus'),
            ]);
        }

        return $audits;
    }

    private function cguThreshold(CustomsWarehouseAuthorization $authorization, Carbon $date, ?string $userId): AeoComplianceAudit
    {
        $percent = $authorization->utilizationPercent();
        $status = ComplianceClassifier::cguThreshold($percent);

        return $this->record($authorization, AuditType::CGUThresholdCheck, $date, $userId, [
            'discrepancy_status' => $status,
            'findings' => match ($status) {
                DiscrepancyStatus::CriticalAlertADMUDSC => 'cgu_utilization_critical',
                DiscrepancyStatus::MinorVariance => 'cgu_utilization_warning',
                default => 'cgu_utilization_ok',
            },
            'corrective_action' => $status === DiscrepancyStatus::Matched ? null : 'increase_guarantee_or_discharge_stock',
        ]);
    }

    /**
     * @return list<AeoComplianceAudit>
     */
    private function usualHandling(CustomsWarehouseAuthorization $authorization, Carbon $date, ?string $userId): array
    {
        $audits = [];
        $lots = BondedMrnLot::query()
            ->where('authorization_id', $authorization->id)
            ->where('customs_status', CustomsStatus::UsualHandlingInProgress->value)
            ->with('discharges')
            ->get();

        foreach ($lots as $lot) {
            $last = $lot->discharges->last();
            $days = $last === null ? 0 : (int) $last->discharge_date->diffInDays($date, false);
            $late = $days > self::USUAL_HANDLING_MAX_DAYS;

            $audits[] = $this->record($authorization, AuditType::UsualHandlingVerification, $date, $userId, [
                'mrn_lot_id' => (string) $lot->id,
                'product_id' => $lot->product_id,
                'customs_ledger_quantity' => (float) $lot->remaining_quantity,
                'discrepancy_status' => $late ? DiscrepancyStatus::MinorVariance : DiscrepancyStatus::Matched,
                'findings' => $late ? 'usual_handling_open_too_long' : 'usual_handling_in_progress',
                'corrective_action' => $late ? 'close_usual_handling' : null,
            ]);
        }

        return $audits;
    }

    /**
     * @return list<AeoComplianceAudit>
     */
    private function partnerChecks(CustomsWarehouseAuthorization $authorization, Carbon $date, RunAeoReconciliationAndComplianceCheckCommand $command): array
    {
        $audits = [];

        foreach ($command->partnerChecks as $check) {
            $status = AeoStatus::from($check['partner_aeo_status']);
            $secure = $check['security_seal_verified'] && $status !== AeoStatus::None;

            $audits[] = $this->record($authorization, AuditType::SecurityPartnerCheck, $date, $command->userId, [
                'partner_or_carrier_name' => $check['partner_or_carrier_name'],
                'partner_aeo_status' => $status,
                'security_seal_verified' => $check['security_seal_verified'],
                'discrepancy_status' => $check['security_seal_verified'] ? ($status === AeoStatus::None ? DiscrepancyStatus::MinorVariance : DiscrepancyStatus::Matched) : DiscrepancyStatus::CriticalAlertADMUDSC,
                'findings' => $check['findings'] ?? ($secure ? 'partner_secure' : ($check['security_seal_verified'] ? 'partner_without_aeo' : 'seal_not_verified')),
                'corrective_action' => $check['corrective_action'],
            ]);
        }

        return $audits;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(CustomsWarehouseAuthorization $authorization, AuditType $type, Carbon $date, ?string $userId, array $attributes): AeoComplianceAudit
    {
        return AeoComplianceAudit::query()->create([
            'tenant_id' => $authorization->tenant_id,
            'authorization_id' => $authorization->id,
            'audit_type' => $type,
            'audit_date' => $date->toDateString(),
            'auditor_user_id' => $userId,
        ] + $attributes)->refresh();
    }
}
