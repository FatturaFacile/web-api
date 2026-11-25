<?php

declare(strict_types=1);

namespace Modules\Plm\CQRS\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Plm\CQRS\Commands\ApproveEcoAndExecuteRevisionCutoverCommand;
use Modules\Plm\Enums\ApprovalDepartment;
use Modules\Plm\Enums\EcoWorkflowStage;
use Modules\Plm\Enums\RevisionLifecycleState;
use Modules\Plm\Enums\StockDispositionRule;
use Modules\Plm\Models\PlmChangeApproval;
use Modules\Plm\Models\PlmChangeOrder;
use Modules\Plm\Models\PlmItemRevision;

final readonly class ApproveEcoAndExecuteRevisionCutoverHandler
{
    public function __construct(private BillOfMaterialsServiceInterface $boms) {}

    public function handle(ApproveEcoAndExecuteRevisionCutoverCommand $command): PlmChangeOrder
    {
        return DB::transaction(function () use ($command): PlmChangeOrder {
            $order = PlmChangeOrder::query()
                ->where('tenant_id', $command->tenantId)
                ->whereKey($command->changeOrderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === PlmChangeOrder::Implemented) {
                throw ValidationException::withMessages(['change_order' => [__('plm_errors.order.already_implemented')]]);
            }

            // Require either full 4-department sign-off matrix OR legacy approved status.
            $hasMatrix = is_array($order->approvals_matrix_json) && $order->approvals_matrix_json !== [];
            if ($hasMatrix && ! $order->hasAllDepartmentApprovals()) {
                throw ValidationException::withMessages(['approvals_matrix' => [__('plm_errors.order.department_approvals_required')]]);
            }

            if (! $hasMatrix && $order->status !== PlmChangeOrder::Approved) {
                $pending = PlmChangeApproval::query()
                    ->where('change_order_id', $order->id)
                    ->where('status', PlmChangeApproval::Pending)
                    ->exists();
                if ($pending || $order->status !== PlmChangeOrder::Approved) {
                    throw ValidationException::withMessages(['change_order' => [__('plm_errors.order.not_approved')]]);
                }
            }

            $isImmediateSafety = $command->forceImmediateSafetyCutover || $order->isImmediateSafetyCutover();
            $effectiveDate = $order->effective_date?->toDateString() ?? now()->toDateString();
            $lotCutover = $command->lotSerialCutoverCode ?? $order->lot_serial_cutover_code;
            $totalQuarantinedLots = 0;
            $totalMesObsoleted = 0;
            $releasedRevisions = [];

            foreach ($order->affectedItems()->with('item')->get() as $affected) {
                $rule = StockDispositionRule::tryFrom((string) ($affected->disposition_rule ?? $order->existing_stock_disposition_rule))
                    ?? StockDispositionRule::UseUpEsaurimentoScorte;

                $itemImmediate = $isImmediateSafety || $rule === StockDispositionRule::ScrapImmediately;

                // Determine on-hand stock in Mod 060 for Phase-Out Use-Up Stock coordination
                $onHandStock = 0.0;
                $item = $affected->item;
                if ($item instanceof MfgItem && $item->stock_item_id !== null) {
                    $onHandStock = (float) StockQuant::withoutGlobalScopes()
                        ->where('tenant_id', $order->tenant_id)
                        ->where('stock_item_id', $item->stock_item_id)
                        ->whereNull('deleted_at')
                        ->sum('quantity_on_hand');
                }

                // Transition previous released revisions of this item
                $previousReleased = PlmItemRevision::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('mfg_item_id', $affected->mfg_item_id)
                    ->whereIn('status', [PlmItemRevision::Released])
                    ->get();

                $quarantinedForItem = 0;
                $mesObsoletedForItem = 0;

                foreach ($previousReleased as $prev) {
                    $targetOldLifecycle = (! $itemImmediate && $rule === StockDispositionRule::UseUpEsaurimentoScorte && $onHandStock > 0.0)
                        ? RevisionLifecycleState::PhaseOutUseUpStock
                        : RevisionLifecycleState::ObsoleteBlocked;

                    $prev->update([
                        'status' => $targetOldLifecycle === RevisionLifecycleState::PhaseOutUseUpStock ? PlmItemRevision::Released : PlmItemRevision::Obsolete,
                        'lifecycle_state' => $targetOldLifecycle->value,
                        'obsoleted_at' => $targetOldLifecycle === RevisionLifecycleState::ObsoleteBlocked ? now() : null,
                        'mes_drawing_obsolete_watermark' => $itemImmediate || $targetOldLifecycle === RevisionLifecycleState::ObsoleteBlocked,
                    ]);

                    if ($itemImmediate || $targetOldLifecycle === RevisionLifecycleState::ObsoleteBlocked) {
                        $mesObsoletedForItem++;
                    }
                }

                // Apply Mod 060 stock & lot disposition
                if ($item instanceof MfgItem && $item->stock_item_id !== null) {
                    if ($itemImmediate) {
                        $quarantinedForItem = StockLot::withoutGlobalScopes()
                            ->where('tenant_id', $order->tenant_id)
                            ->where('stock_item_id', $item->stock_item_id)
                            ->where('is_quarantined', false)
                            ->update([
                                'is_quarantined' => true,
                                'notes' => trim("ECO {$order->code} immediate safety cutover quarantine"),
                            ]);

                        StockReservation::withoutGlobalScopes()
                            ->where('tenant_id', $order->tenant_id)
                            ->where('stock_item_id', $item->stock_item_id)
                            ->where('status', 'active')
                            ->update(['status' => 'cancelled']);
                    } elseif ($rule === StockDispositionRule::UseUpEsaurimentoScorte && $onHandStock > 0.0) {
                        $attrs = $item->custom_attributes ?? [];
                        $attrs['plm_phase_out'] = [
                            'eco_code' => $order->code,
                            'rule' => $rule->value,
                            'consume_existing_stock_first' => true,
                            'on_hand_at_cutover' => $onHandStock,
                            'target_revision' => $affected->to_revision,
                        ];
                        $item->update(['custom_attributes' => $attrs]);
                    } elseif ($rule === StockDispositionRule::ReworkInHouse) {
                        $attrs = $item->custom_attributes ?? [];
                        $attrs['plm_rework_required'] = [
                            'eco_code' => $order->code,
                            'target_revision' => $affected->to_revision,
                            'flagged_at' => now()->toIso8601String(),
                        ];
                        $item->update(['custom_attributes' => $attrs]);
                    }
                }

                // Update Mod 061 open production orders if immediate safety cutover applies
                if ($itemImmediate) {
                    $openOrdersCount = MfgProductionOrder::query()
                        ->where('tenant_id', $order->tenant_id)
                        ->where('mfg_item_id', $affected->mfg_item_id)
                        ->whereIn('status', ['planned', 'released', 'in_progress'])
                        ->count();
                    $mesObsoletedForItem += $openOrdersCount;
                }

                // Create or release target revision
                $targetRevision = PlmItemRevision::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('mfg_item_id', $affected->mfg_item_id)
                    ->where('revision', $affected->to_revision)
                    ->first();

                $parentRev = $previousReleased->first();

                if ($targetRevision instanceof PlmItemRevision) {
                    $targetRevision->update([
                        'status' => PlmItemRevision::Released,
                        'lifecycle_state' => RevisionLifecycleState::ReleasedForProduction->value,
                        'parent_revision_id' => $targetRevision->parent_revision_id ?? $parentRev?->id,
                        'change_summary' => $affected->change_summary ?? $order->title,
                        'change_order_id' => $order->id,
                        'effective_from' => $affected->effective_date?->toDateString() ?? $effectiveDate,
                        'released_at' => now(),
                        'mes_drawing_obsolete_watermark' => false,
                    ]);
                } else {
                    $targetRevision = PlmItemRevision::create([
                        'tenant_id' => $order->tenant_id,
                        'mfg_item_id' => $affected->mfg_item_id,
                        'parent_revision_id' => $parentRev?->id,
                        'engineering_part_number' => $item?->item_code,
                        'revision' => $affected->to_revision,
                        'status' => PlmItemRevision::Released,
                        'lifecycle_state' => RevisionLifecycleState::ReleasedForProduction->value,
                        'change_summary' => $affected->change_summary ?? $order->title,
                        'change_order_id' => $order->id,
                        'effective_from' => $affected->effective_date?->toDateString() ?? $effectiveDate,
                        'released_at' => now(),
                        'mes_drawing_obsolete_watermark' => false,
                    ]);
                }

                foreach ($previousReleased as $prev) {
                    $prev->update(['superseded_by_revision_id' => $targetRevision->id]);
                }

                $affected->update([
                    'from_revision_id' => $parentRev?->id,
                    'target_revision_id' => $targetRevision->id,
                    'cutover_mode' => $itemImmediate ? 'ImmediateSafetyCutover' : 'PhaseOut_UseUpStock',
                    'lot_serial_cutover' => $affected->lot_serial_cutover ?? $lotCutover,
                    'quarantined_lots_count' => $quarantinedForItem,
                    'mes_drawings_obsoleted_count' => $mesObsoletedForItem,
                ]);

                $totalQuarantinedLots += $quarantinedForItem;
                $totalMesObsoleted += $mesObsoletedForItem;
                $releasedRevisions[] = [
                    'mfg_item_id' => (string) $affected->mfg_item_id,
                    'revision_id' => (string) $targetRevision->id,
                    'revision' => $targetRevision->revision,
                    'disposition_rule' => $rule->value,
                ];
            }

            // Activate draft affected BOMs in Mod 059
            foreach ($order->affectedBoms()->with('bom')->get() as $affectedBom) {
                $bom = $affectedBom->bom;
                if ($bom instanceof MfgBom && $bom->isDraft() && $bom->components()->exists()) {
                    $this->boms->activate($bom, $command->actorUserId);
                }
            }

            $ecnNumber = $order->ecn_number ?? 'ECN-'.now()->format('Ymd').'-'.strtoupper(substr((string) $order->id, 0, 6));

            $order->update([
                'status' => PlmChangeOrder::Implemented,
                'workflow_stage' => EcoWorkflowStage::EcnReleased->value,
                'implemented_at' => now(),
                'ecn_number' => $ecnNumber,
                'ecn_released_at' => now(),
                'lot_serial_cutover_code' => $lotCutover,
                'cutover_summary_json' => [
                    'ecn_number' => $ecnNumber,
                    'immediate_safety_cutover' => $isImmediateSafety,
                    'quarantined_lots_count' => $totalQuarantinedLots,
                    'mes_drawings_obsoleted_count' => $totalMesObsoleted,
                    'released_revisions' => $releasedRevisions,
                    'departments_verified' => array_map(static fn (ApprovalDepartment $d): string => $d->value, ApprovalDepartment::cases()),
                    'executed_at' => now()->toIso8601String(),
                ],
            ]);

            return $order->refresh()->load(['approvals.approver:id,name', 'affectedItems.item:id,item_code,name', 'affectedBoms.bom:id,bom_number,name,revision,status']);
        });
    }
}
