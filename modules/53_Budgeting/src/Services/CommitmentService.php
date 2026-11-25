<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Budgeting\Models\BudgetAuditEvent;
use Modules\Budgeting\Models\BudgetCommitment;

/**
 * Commitments of requisitions and purchase orders against the budget. A commitment is written when a document is
 * approved, consumed as the goods are received or invoiced, and released when the document is cancelled. A requisition
 * stops counting for the part its purchase orders have taken over, so the same spend is never counted twice.
 */
final readonly class CommitmentService
{
    public function __construct(private BaselineResolver $baselines, private SpendControlService $control, private AuditTrail $audit) {}

    /**
     * Writes or updates the commitment of a document. A commitment that was released stays released.
     *
     * @param  array{tenant_id: string, source_type: string, source_id: string, source_number: string|null, parent_source_id: string|null, account_id: string|null, cost_center_id: string|null, project_id: string|null, date: Carbon, currency: string, amount: string, consumed: string, user_id?: string|null}  $facts
     */
    public function sync(array $facts): BudgetCommitment
    {
        return DB::transaction(function () use ($facts): BudgetCommitment {
            $version = $this->baselines->forDate($facts['tenant_id'], $facts['date']);
            $existing = BudgetCommitment::query()
                ->where('tenant_id', $facts['tenant_id'])
                ->where('source_type', $facts['source_type'])
                ->where('source_id', $facts['source_id'])
                ->lockForUpdate()
                ->first();

            $consumed = bccomp($facts['consumed'], $facts['amount'], 2) > 0 ? $facts['amount'] : $facts['consumed'];
            $values = [
                'source_number' => $facts['source_number'],
                'parent_source_id' => $facts['parent_source_id'],
                'account_id' => $facts['account_id'],
                'cost_center_id' => $facts['cost_center_id'],
                'project_id' => $facts['project_id'],
                'fiscal_year' => $version->fiscal_year ?? $facts['date']->year,
                'period' => $version !== null ? BudgetPeriods::periodOfMonth($version, $facts['date']->format('Y-m')) : null,
                'commitment_date' => $facts['date']->toDateString(),
                'currency' => $facts['currency'],
                'amount' => $facts['amount'],
                'consumed_amount' => $consumed,
            ];

            if ($existing === null) {
                $commitment = BudgetCommitment::create([
                    ...$values,
                    'tenant_id' => $facts['tenant_id'],
                    'source_type' => $facts['source_type'],
                    'source_id' => $facts['source_id'],
                    'released_amount' => '0.00',
                    'status' => BudgetCommitment::Open,
                ])->refresh();
                $this->close($commitment);
                $this->audit->record($commitment->tenant_id, BudgetAuditEvent::SubjectCommitment, $commitment->id, 'commit', $facts['user_id'] ?? null, null, $commitment->status, null, ['source' => $commitment->source_type.':'.$commitment->source_number, 'amount' => $commitment->amount]);
                $this->control->settle($commitment, $facts['user_id'] ?? null);

                return $commitment->refresh();
            }

            if ($existing->status === BudgetCommitment::Released) {
                return $existing;
            }

            $existing->update($values);
            $this->close($existing);

            return $existing->refresh();
        });
    }

    /**
     * The document is cancelled or no longer commits the budget.
     */
    public function release(string $tenantId, string $sourceType, string $sourceId, string $reason, ?string $userId = null): void
    {
        $commitment = BudgetCommitment::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', '!=', BudgetCommitment::Released)
            ->first();

        if ($commitment === null) {
            return;
        }

        $from = $commitment->status;
        $commitment->update(['status' => BudgetCommitment::Released, 'release_reason' => $reason, 'closed_at' => now()]);
        $this->audit->record($tenantId, BudgetAuditEvent::SubjectCommitment, $commitment->id, 'release', $userId, $from, BudgetCommitment::Released, $reason);
    }

    /**
     * A requisition counts only for what its purchase orders have not taken over yet (no double counting while the
     * requisition is being turned into orders), and counts again when an order is cancelled.
     */
    public function reconcileRequisition(string $tenantId, string $requisitionId): void
    {
        $requisition = BudgetCommitment::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', BudgetCommitment::Requisition)
            ->where('source_id', $requisitionId)
            ->where('status', '!=', BudgetCommitment::Released)
            ->first();

        if ($requisition === null) {
            return;
        }

        $taken = '0.00';

        foreach (BudgetCommitment::query()->where('tenant_id', $tenantId)->where('parent_source_id', $requisitionId)->where('source_type', BudgetCommitment::PurchaseOrder)->where('status', '!=', BudgetCommitment::Released)->get() as $order) {
            $taken = bcadd($taken, (string) $order->amount, 2);
        }

        $released = bccomp($taken, (string) $requisition->amount, 2) > 0 ? (string) $requisition->amount : $taken;
        $requisition->update(['released_amount' => $released]);
        $this->close($requisition);
    }

    private function close(BudgetCommitment $commitment): void
    {
        $remaining = bcsub(bcsub((string) $commitment->amount, (string) $commitment->consumed_amount, 2), (string) $commitment->released_amount, 2);
        $closed = bccomp($remaining, '0', 2) <= 0;

        if ($closed && $commitment->status === BudgetCommitment::Open) {
            $commitment->update(['status' => BudgetCommitment::Closed, 'closed_at' => now()]);
        } elseif (! $closed && $commitment->status === BudgetCommitment::Closed) {
            $commitment->update(['status' => BudgetCommitment::Open, 'closed_at' => null]);
        }
    }
}
