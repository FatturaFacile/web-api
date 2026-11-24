<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Models\PassiveInvoice;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Enums\InvoiceMatchLineStatus;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Enums\MatchResolutionAction;
use Modules\Procurement\Enums\MatchTrigger;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\InvoiceMatchingRun;
use Modules\Procurement\Models\InvoiceMatchLine;
use Modules\Procurement\Models\InvoiceMatchResolution;
use Modules\Procurement\Support\Decimal;

/**
 * Exception resolution of a blocked invoice: request a credit note from the supplier, re-match against updated order
 * and receipt data, accept some of the discrepant lines (partial resolution) or override the whole block as supervisor
 * or CFO. Every action is written to the resolution history and to the audit ledger.
 */
final class InvoiceMatchResolutionService
{
    public function __construct(
        private readonly InvoiceMatchingService $matching,
        private readonly ProcurementAuditLedgerService $auditLedger,
    ) {}

    /**
     * The supplier is asked for a credit note for the variance: the invoice stays blocked until it is re-matched or
     * resolved.
     *
     * @throws ProcurementRuleViolation when the run does not block the invoice
     */
    public function requestCreditNote(InvoiceMatchingRun $run, ?string $actorId, string $reason, ?string $expectedCredit = null): InvoiceMatchingRun
    {
        return DB::transaction(function () use ($run, $actorId, $reason, $expectedCredit): InvoiceMatchingRun {
            $locked = $this->lockBlocking($run);
            $from = $locked->status;

            $locked->forceFill(['status' => InvoiceMatchStatus::PendingCreditNote])->save();
            PassiveInvoice::query()->whereKey($locked->passive_invoice_id)->lockForUpdate()->first()?->blockForMatch(InvoiceMatchStatus::PendingCreditNote->value);

            $credit = Decimal::of($expectedCredit ?? $locked->variance_amount, 2);
            $this->record($locked, MatchResolutionAction::RequestCreditNote, $from, $locked->status, $actorId, $reason, ['expected_credit_amount' => $credit]);

            return $locked->refresh();
        });
    }

    /**
     * Evaluates the invoice again with the current order and receipt data (a goods receipt booked late, a corrected
     * order price). The new run becomes the current one.
     *
     * @throws ProcurementRuleViolation when the run does not block the invoice
     */
    public function rematch(InvoiceMatchingRun $run, ?string $actorId, ?string $reason = null): InvoiceMatchingRun
    {
        return DB::transaction(function () use ($run, $actorId, $reason): InvoiceMatchingRun {
            $previous = $this->lockBlocking($run);
            $fresh = $this->matching->run((string) $previous->passive_invoice_id, $previous->purchase_order_id, MatchTrigger::Rematch, $actorId);

            $this->record($fresh, MatchResolutionAction::Rematch, $previous->status, $fresh->status, $actorId, $reason, ['previous_run_id' => (string) $previous->id]);

            return $fresh->refresh();
        });
    }

    /**
     * Accepts the named discrepant lines. When no discrepant line is left the invoice is released (status resolved).
     *
     * @param  list<string>  $lineIds
     *
     * @throws ProcurementRuleViolation when the run does not block the invoice or a line is not a discrepancy of it
     */
    public function resolvePartially(InvoiceMatchingRun $run, ?string $actorId, string $reason, array $lineIds): InvoiceMatchingRun
    {
        return DB::transaction(function () use ($run, $actorId, $reason, $lineIds): InvoiceMatchingRun {
            $locked = $this->lockBlocking($run);
            $from = $locked->status;
            $lines = InvoiceMatchLine::query()->where('run_id', $locked->id)->whereIn('id', $lineIds)->get();

            if ($lineIds === [] || $lines->count() !== count(array_unique($lineIds)) || $lines->contains(static fn (InvoiceMatchLine $line): bool => ! $line->status->isBlocking())) {
                throw new ProcurementRuleViolation('Choose discrepant lines of this matching run.', 'line_ids');
            }

            $this->accept($lines->all());

            $remaining = InvoiceMatchLine::query()->where('run_id', $locked->id)->whereIn('status', InvoiceMatchLineStatus::blockingValues())->count();
            $this->refreshTotals($locked, $remaining);

            if ($remaining === 0) {
                $this->matching->release($locked, InvoiceMatchStatus::Resolved);
            }

            $this->record($locked, MatchResolutionAction::PartialResolution, $from, $locked->refresh()->status, $actorId, $reason, ['line_ids' => $lineIds, 'remaining_discrepancies' => $remaining]);

            return $locked->refresh();
        });
    }

    /**
     * Supervisor / CFO override: every discrepant line is accepted and the invoice is released for payment. The route
     * requires the accounts payable approval permission; a reason is mandatory.
     *
     * @throws ProcurementRuleViolation when the run does not block the invoice or the reason is empty
     */
    public function override(InvoiceMatchingRun $run, ?string $actorId, string $reason): InvoiceMatchingRun
    {
        if (trim($reason) === '') {
            throw new ProcurementRuleViolation('Give the reason of the override.', 'reason');
        }

        return DB::transaction(function () use ($run, $actorId, $reason): InvoiceMatchingRun {
            $locked = $this->lockBlocking($run);
            $from = $locked->status;
            $lines = InvoiceMatchLine::query()->where('run_id', $locked->id)->get()->filter(static fn (InvoiceMatchLine $line): bool => $line->status->isBlocking());

            $this->accept($lines->all());
            $this->refreshTotals($locked, 0);
            $this->matching->release($locked, InvoiceMatchStatus::OverrideApproved);

            $this->record($locked, MatchResolutionAction::Override, $from, InvoiceMatchStatus::OverrideApproved, $actorId, $reason, [
                'accepted_lines' => $lines->count(),
                'variance_amount' => (string) $locked->variance_amount,
            ]);

            return $locked->refresh();
        });
    }

    private function lockBlocking(InvoiceMatchingRun $run): InvoiceMatchingRun
    {
        $locked = InvoiceMatchingRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

        if (! $locked->is_current || ! $locked->status->isBlocking()) {
            throw new ProcurementRuleViolation("A matching run in status {$locked->status->value} has no discrepancy to resolve.", 'status');
        }

        return $locked;
    }

    /**
     * @param  list<InvoiceMatchLine>  $lines
     */
    private function accept(array $lines): void
    {
        foreach ($lines as $line) {
            $line->forceFill(['status' => InvoiceMatchLineStatus::Accepted, 'gr_ir_amount' => $this->accrualCleared($line)])->save();
        }
    }

    /**
     * The accrual an accepted line clears: the quantity received and not yet billed, at the order price. Goods that
     * were never received clear nothing; the invoiced value is then a cost.
     */
    private function accrualCleared(InvoiceMatchLine $line): string
    {
        if (! $line->receipt_required) {
            return '0.00';
        }

        $billable = Decimal::max(Decimal::sub((string) $line->received_quantity, (string) $line->previously_invoiced_quantity, 4), '0.0000', 4);

        return Decimal::mul(Decimal::min((string) $line->invoiced_quantity, $billable, 4), (string) $line->ordered_unit_price, 2);
    }

    private function refreshTotals(InvoiceMatchingRun $run, int $remaining): void
    {
        $grIr = InvoiceMatchLine::query()->where('run_id', $run->id)->get()->reduce(static fn (string $sum, InvoiceMatchLine $line): string => Decimal::add($sum, (string) $line->gr_ir_amount, 2), '0.00');

        $run->forceFill([
            'discrepancy_count' => $remaining,
            'gr_ir_amount' => $grIr,
            'price_variance_amount' => Decimal::max(Decimal::sub((string) $run->invoiced_total, $grIr, 2), '0.00', 2),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(InvoiceMatchingRun $run, MatchResolutionAction $action, InvoiceMatchStatus $from, InvoiceMatchStatus $to, ?string $actorId, ?string $reason, array $context): void
    {
        InvoiceMatchResolution::create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->id,
            'passive_invoice_id' => $run->passive_invoice_id,
            'action' => $action->value,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'actor_id' => $actorId,
            'context' => $context,
            'resolved_at' => now(),
        ]);

        $this->auditLedger->record(
            action: 'INVOICE_MATCH_'.strtoupper($action->value),
            model: $run,
            payloadBefore: ['status' => $from->value],
            payloadAfter: ['status' => $to->value, 'reason' => $reason] + $context,
        );
    }
}
