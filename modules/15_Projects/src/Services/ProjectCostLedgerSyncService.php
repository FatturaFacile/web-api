<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Projects\Contracts\ProjectFxRateResolverInterface;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectCostLedgerEntry;
use Modules\Projects\Support\CostSourceType;
use Modules\Projects\Support\Money;

/**
 * Keeps the analytical cost ledger of a project (Mod 082) in step with its source documents, idempotently.
 *
 * Actual cost: every approved job-cost entry (approved timesheets of Mod 080, employee expenses of Mod 075, goods
 * receipts and vendor costs of Mods 046/048, warehouse issues of Mod 051) becomes one ledger line on its WBS node.
 * Committed cost: each open purchase order (Mod 046) becomes one line holding only the quantity still to receive, so a
 * commitment shrinks as goods are received (they turn into actual cost through the goods receipt) and never counts twice;
 * a closed order keeps its line at zero. Overhead allocations are written by OverheadAllocationService, not here.
 * Amounts are converted into the base currency at the rate of the posting date.
 */
final readonly class ProjectCostLedgerSyncService
{
    public function __construct(private ProjectFxRateResolverInterface $fx) {}

    public function sync(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $this->syncActualCosts($project);
            $this->syncCommitments($project);
        });
    }

    private function syncActualCosts(Project $project): void
    {
        $currency = $this->currencyOf($project);

        $approved = ProjectCostEntry::query()->where('project_id', $project->id)->approved()->get();

        // Lines of entries that are no longer approved (rejected, deleted) leave the ledger.
        ProjectCostLedgerEntry::query()->where('project_id', $project->id)
            ->whereIn('source_type', [CostSourceType::TimesheetLabor->value, CostSourceType::EmployeeExpense->value, CostSourceType::VendorInvoice->value, CostSourceType::WarehouseMaterialIssue->value])
            ->whereNotIn('source_document_id', $approved->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all())
            ->get()->each(static fn (ProjectCostLedgerEntry $stale): ?bool => $stale->delete());

        $approved->each(function (ProjectCostEntry $entry) use ($project, $currency): void {
            $date = $entry->incurred_date->toDateString();
            $rate = $this->rate($project, $currency, $date);
            $amount = Money::of($entry->total_cost);

            $this->upsert($project, CostSourceType::fromCostCategory($entry->cost_category), (string) $entry->id, [
                'wbs_node_id' => $entry->project_task_id,
                'source_document_reference' => $entry->cost_source_type,
                'committed_amount' => '0',
                'actual_amount' => $amount,
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'base_currency_committed_amount' => '0',
                'base_currency_actual_amount' => Money::mul($amount, $rate),
                'posting_date' => $date,
            ]);
        });
    }

    private function syncCommitments(Project $project): void
    {
        $orders = PurchaseOrder::query()->where('project_id', $project->id)->get();
        $open = $orders->filter(static fn (PurchaseOrder $order): bool => in_array($order->status, ProjectCommitmentService::OpenStatuses, true));

        foreach ($orders as $order) {
            $amount = $open->contains('id', $order->id) ? $this->openValue((string) $order->id) : '0';
            $currency = strtoupper((string) ($order->getAttribute('currency') ?? $this->currencyOf($project)));
            $date = (string) ($order->getAttribute('order_date') ?? now()->toDateString());
            $date = substr($date, 0, 10);
            $rate = $this->rate($project, $currency, $date);

            $this->upsert($project, CostSourceType::PurchaseCommitment, (string) $order->id, [
                'wbs_node_id' => null,
                'source_document_reference' => $order->getAttribute('po_number'),
                'committed_amount' => $amount,
                'actual_amount' => '0',
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'base_currency_committed_amount' => Money::mul($amount, $rate),
                'base_currency_actual_amount' => '0',
                'posting_date' => $date,
            ]);
        }
    }

    private function openValue(string $orderId): string
    {
        $open = Money::of(0);

        PurchaseOrderLine::query()->where('purchase_order_id', $orderId)->where('is_closed', false)
            ->get(['quantity_ordered', 'quantity_received', 'quantity_cancelled', 'unit_price'])
            ->each(function (PurchaseOrderLine $line) use (&$open): void {
                $remaining = Money::sub(Money::sub($line->getAttribute('quantity_ordered'), $line->getAttribute('quantity_received')), $line->getAttribute('quantity_cancelled'));

                if (Money::isPositive($remaining)) {
                    $open = Money::add($open, Money::mul($remaining, $line->getAttribute('unit_price')));
                }
            });

        return $open;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function upsert(Project $project, CostSourceType $type, string $documentId, array $values): void
    {
        $entry = ProjectCostLedgerEntry::query()->withTrashed()->where('project_id', $project->id)->where('source_type', $type->value)->where('source_document_id', $documentId)->first()
            ?? new ProjectCostLedgerEntry(['tenant_id' => $project->tenant_id, 'company_id' => $project->company_id, 'project_id' => $project->id, 'source_type' => $type->value, 'source_document_id' => $documentId]);

        foreach (['committed_amount', 'actual_amount', 'base_currency_committed_amount', 'base_currency_actual_amount'] as $key) {
            $values[$key] = Money::round($values[$key], 4);
        }

        $entry->fill($values);

        if ($entry->trashed()) {
            $entry->restore();
        }

        $entry->save();
    }

    private function currencyOf(Project $project): string
    {
        return strtoupper((string) ($project->currency ?? 'EUR'));
    }

    /**
     * Base currency per unit of currency; 1 while the exchange-rate service has no rate for the day.
     */
    private function rate(Project $project, string $currency, string $date): string
    {
        return $this->fx->rateOn((string) $project->tenant_id, $currency, $date) ?? '1.000000';
    }
}
