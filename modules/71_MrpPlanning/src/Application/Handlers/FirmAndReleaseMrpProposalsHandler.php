<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Handlers;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;
use Modules\MrpPlanning\Application\Commands\FirmAndReleaseMrpProposalsCommand;
use Modules\MrpPlanning\Domain\Contracts\ReleaseGatewayInterface;
use Modules\MrpPlanning\Domain\Enums\ProposalStatus;
use Modules\MrpPlanning\Domain\Enums\ProposalType;
use Modules\MrpPlanning\Domain\Enums\ReleaseAction;
use Modules\MrpPlanning\Domain\Events\MrpPlanningEvent;
use Modules\MrpPlanning\Domain\Exceptions\MrpException;
use Modules\MrpPlanning\Domain\Exceptions\MrpNotFoundException;
use Modules\MrpPlanning\Domain\Services\LotSizingCalculator;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;
use Modules\MrpPlanning\Infrastructure\Models\MrpItemParameter;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;

/**
 * Firm, release or dismiss a selection of proposals, all or nothing.
 *
 * - Firm: the planner commits to a planned order; later runs keep it as a scheduled receipt and do not replan it.
 * - Release: purchase proposals become one draft purchase requisition per supplier (Mod 044, from which the purchase
 *   orders of Mod 046 are raised); production and subcontract proposals become planned production orders (Mod 064) with
 *   the good output as target quantity. The created document is recorded on the proposal.
 * - Dismiss: the proposal (or advice on an open order) is set aside.
 */
final class FirmAndReleaseMrpProposalsHandler
{
    public function __construct(
        private readonly ReleaseGatewayInterface $gateway,
        private readonly LotSizingCalculator $lots,
        private readonly EventDispatcher $dispatcher,
    ) {}

    /**
     * @return array{action: string, processed: int, purchase_requisition_ids: list<string>, production_order_ids: list<string>, proposals: list<MrpActionProposal>}
     */
    public function handle(FirmAndReleaseMrpProposalsCommand $command): array
    {
        $ids = array_values(array_unique($command->proposalIds));
        if ($ids === []) {
            throw new MrpException('Select at least one proposal.');
        }

        return DB::transaction(function () use ($command, $ids): array {
            $proposals = MrpActionProposal::query()->where('tenant_id', $command->tenantId)->whereIn('id', $ids)->lockForUpdate()->get();
            if ($proposals->count() !== count($ids)) {
                throw new MrpNotFoundException('One or more proposals were not found.');
            }
            foreach ($proposals as $proposal) {
                $this->assertAllowed($proposal, $command->action);
            }

            $requisitions = [];
            $orders = [];
            match ($command->action) {
                ReleaseAction::Firm => $this->firm($proposals->all()),
                ReleaseAction::Dismiss => $this->dismiss($proposals->all()),
                ReleaseAction::Release => $this->release($command, $proposals->all(), $requisitions, $orders),
            };

            $this->dispatcher->publishAndDispatch(new MrpPlanningEvent(
                MrpPlanningEvent::ProposalsReleased, 'mrp_proposal_batch', (string) Str::uuid7(),
                ['action' => $command->action->value, 'tenant_id' => $command->tenantId, 'proposal_ids' => $ids, 'purchase_requisition_ids' => $requisitions, 'production_order_ids' => $orders],
                $command->tenantId,
            ));

            return [
                'action' => $command->action->value,
                'processed' => count($ids),
                'purchase_requisition_ids' => $requisitions,
                'production_order_ids' => $orders,
                'proposals' => $proposals->map(static fn (MrpActionProposal $proposal): MrpActionProposal => $proposal->refresh())->all(),
            ];
        });
    }

    private function assertAllowed(MrpActionProposal $proposal, ReleaseAction $action): void
    {
        $label = $proposal->proposal_type->value.' '.$proposal->id;
        $open = in_array($proposal->status, [ProposalStatus::Proposed, ProposalStatus::Firm], true);

        if ($action === ReleaseAction::Dismiss && ! $open) {
            throw new MrpException("Proposal {$label} is {$proposal->status->value} and cannot be dismissed.");
        }
        if ($action === ReleaseAction::Firm && ($proposal->status !== ProposalStatus::Proposed || ! $proposal->proposal_type->isNewOrder())) {
            throw new MrpException("Proposal {$label} cannot be firmed: only proposed new orders can.");
        }
        if ($action === ReleaseAction::Release && (! $open || ! $proposal->proposal_type->isNewOrder())) {
            throw new MrpException("Proposal {$label} cannot be released: only proposed or firm new orders can. Advice on open orders is dismissed instead.");
        }
    }

    /**
     * @param  list<MrpActionProposal>  $proposals
     */
    private function firm(array $proposals): void
    {
        foreach ($proposals as $proposal) {
            $proposal->forceFill(['status' => ProposalStatus::Firm, 'firmed_at' => now()])->save();
        }
    }

    /**
     * @param  list<MrpActionProposal>  $proposals
     */
    private function dismiss(array $proposals): void
    {
        foreach ($proposals as $proposal) {
            $proposal->forceFill(['status' => ProposalStatus::Dismissed])->save();
        }
    }

    /**
     * @param  list<MrpActionProposal>  $proposals
     * @param  list<string>  $requisitions
     * @param  list<string>  $orders
     */
    private function release(FirmAndReleaseMrpProposalsCommand $command, array $proposals, array &$requisitions, array &$orders): void
    {
        $items = MfgItem::query()->where('tenant_id', $command->tenantId)->whereIn('id', array_map(static fn (MrpActionProposal $p): string => (string) $p->mfg_item_id, $proposals))->get()->keyBy('id');

        $purchases = array_values(array_filter($proposals, static fn (MrpActionProposal $p): bool => $p->proposal_type === ProposalType::PlannedPurchaseRequisition));
        if ($purchases !== [] && ($command->userId === null || ! Str::isUuid($command->userId))) {
            throw new MrpException('Releasing purchase proposals needs the requesting user.');
        }

        $suppliers = MrpItemParameter::query()->where('tenant_id', $command->tenantId)->whereIn('mfg_item_id', array_map(static fn (MrpActionProposal $p): string => (string) $p->mfg_item_id, $purchases))->pluck('supplier_id', 'mfg_item_id');
        $known = DB::table('suppliers')->whereIn('id', $suppliers->filter()->values()->all())->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        $groups = [];
        foreach ($purchases as $proposal) {
            $supplier = $suppliers[(string) $proposal->mfg_item_id] ?? null;
            $groups[in_array((string) $supplier, $known, true) ? (string) $supplier : ''][] = $proposal;
        }

        $run = $proposals === [] ? null : MrpRunExecution::query()->where('tenant_id', $command->tenantId)->whereKey($proposals[0]->mrp_run_id)->first();
        foreach ($groups as $supplier => $group) {
            $lines = [];
            $required = null;
            foreach ($group as $proposal) {
                $item = $items[(string) $proposal->mfg_item_id];
                $lines[] = ['description' => $item->item_code.' '.$item->name, 'quantity' => (string) $proposal->suggested_order_qty, 'uom' => $item->uom, 'unit_price' => Money::of($item->standard_cost), 'supplier_id' => $supplier !== '' ? $supplier : null];
                $required = $required === null || $proposal->need_by_date < $required ? CarbonImmutable::parse($proposal->need_by_date) : $required;
            }
            $id = $this->gateway->createPurchaseRequisition($command->tenantId, (string) $command->userId, 'MRP '.($run?->run_code ?? ''), $required ?? CarbonImmutable::today(), $lines, 'Released from MRP proposals');
            $requisitions[] = $id;
            foreach ($group as $proposal) {
                $this->markReleased($proposal, 'purchase_requisition', $id);
            }
        }

        foreach ($proposals as $proposal) {
            if ($proposal->proposal_type === ProposalType::PlannedPurchaseRequisition) {
                continue;
            }
            $item = $items[(string) $proposal->mfg_item_id];
            $id = $this->gateway->createProductionOrder(
                $command->tenantId,
                (string) $proposal->mfg_item_id,
                $this->lots->goodOutput((string) $proposal->suggested_order_qty, Money::of($item->scrap_rate)),
                CarbonImmutable::parse($proposal->planned_start_at ?? $proposal->release_order_by_date),
                CarbonImmutable::parse($proposal->planned_end_at ?? $proposal->need_by_date),
                $proposal->bom_version_id,
                $command->userId !== null && Str::isUuid($command->userId) ? $command->userId : null,
            );
            $orders[] = $id;
            $this->markReleased($proposal, 'production_order', $id);
        }
    }

    private function markReleased(MrpActionProposal $proposal, string $type, string $id): void
    {
        $proposal->forceFill(['status' => ProposalStatus::Released, 'released_at' => now(), 'firm_reference_type' => $type, 'firm_reference_id' => $id])->save();
    }
}
