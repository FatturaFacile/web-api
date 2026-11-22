<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\Contracts\WorkOrderAndFieldServiceInterface;
use Modules\ServiceManagement\DTOs\CompleteWorkOrderDTO;
use Modules\ServiceManagement\DTOs\ResolveTicketDTO;
use Modules\ServiceManagement\Exceptions\HourBankException;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Field intervention -> ticket + billing hand-off. Completing a signed intervention resolves the originating ticket
 * and settles the money side exactly once: labor hours are booked on the covering maintenance contract here (the hour
 * bank is idempotent per work order, so the WorkOrderCompletedEvent listener cannot book them twice) and are not
 * invoiced; when no contract covers them, anything billable is drafted as a sales invoice. Replaying the call on a completed work order never double-books or double-invoices.
 */
final readonly class FieldInterventionBillingService
{
    public const SETTLEMENT_CONTRACT_DEDUCTION = 'contract_deduction';

    public const SETTLEMENT_INVOICE_DRAFT = 'invoice_draft';

    public const SETTLEMENT_NONE = 'none';

    public function __construct(
        private WorkOrderAndFieldServiceInterface $workOrders,
        private TicketServiceInterface $tickets,
        private HourBankService $hourBank,
    ) {}

    /**
     * @return array{work_order: WorkOrder, ticket: Ticket|null, settlement: string, invoice: SalesInvoice|null, hours_deducted: string}
     */
    public function completeAndBill(CompleteWorkOrderDTO $dto, string $taxRate, ?string $dueDate = null, ?string $revenueAccountId = null, ?string $actorUserId = null): array
    {
        return DB::transaction(function () use ($dto, $taxRate, $dueDate, $revenueAccountId, $actorUserId): array {
            $workOrder = $this->workOrders->completeWorkOrder($dto, $actorUserId)->refresh();

            $ticket = $this->resolveTicket($workOrder, $dto, $actorUserId);

            $hours = $this->deductContractHours($workOrder, $ticket);
            $deducted = bccomp($hours, '0', 2) > 0;

            $invoice = null;
            $settlement = self::SETTLEMENT_NONE;

            if ($deducted) {
                $settlement = self::SETTLEMENT_CONTRACT_DEDUCTION;
            } elseif ($workOrder->invoice_id !== null) {
                $invoice = SalesInvoice::query()->find($workOrder->invoice_id);
                $settlement = self::SETTLEMENT_INVOICE_DRAFT;
            } elseif ($workOrder->is_billable && $workOrder->customer_id !== null && bccomp((string) $workOrder->total_billable_amount, '0', 2) > 0) {
                $invoice = $this->workOrders->invoiceWorkOrder($workOrder->id, $taxRate, $dueDate, $revenueAccountId, $actorUserId);
                $settlement = self::SETTLEMENT_INVOICE_DRAFT;
            }

            return [
                'work_order' => $workOrder->refresh(),
                'ticket' => $ticket?->refresh(),
                'settlement' => $settlement,
                'invoice' => $invoice,
                'hours_deducted' => $hours,
            ];
        });
    }

    /**
     * Books the labor hours of the work order on its covering contract. Returns the booked hours, or 0.00 when no
     * active contract with an hour bank covers it or the balance is too low (the work is then billed instead).
     */
    private function deductContractHours(WorkOrder $workOrder, ?Ticket $ticket): string
    {
        $contractId = $workOrder->service_contract_id ?? $ticket?->service_contract_id;
        $contract = $contractId !== null ? ServiceContract::query()->find($contractId) : null;
        $hours = number_format((float) $workOrder->total_labor_hours, 2, '.', '');

        if ($contract === null || bccomp((string) $contract->included_hours, '0', 2) <= 0 || bccomp($hours, '0', 2) <= 0) {
            return '0.00';
        }

        try {
            $this->hourBank->deduct($contract, $hours, [
                'work_order_id' => (string) $workOrder->id,
                'ticket_id' => $workOrder->ticket_id,
                'reference' => $workOrder->work_order_number,
            ]);
        } catch (HourBankException) {
            return '0.00';
        }

        return $hours;
    }

    private function resolveTicket(WorkOrder $workOrder, CompleteWorkOrderDTO $dto, ?string $actorUserId): ?Ticket
    {
        if ($workOrder->ticket_id === null) {
            return null;
        }

        $ticket = Ticket::query()->find($workOrder->ticket_id);
        if ($ticket === null) {
            return null;
        }

        if (in_array($ticket->status, ['resolved', 'closed', 'cancelled'], true) || $ticket->merged_into_ticket_id !== null) {
            return $ticket;
        }

        return $this->tickets->resolveTicket(new ResolveTicketDTO(
            ticketId: (string) $ticket->id,
            resolutionNotes: $dto->resolutionSummary ?? $dto->completionNotes ?? $workOrder->resolution_notes,
            resolvedBy: $actorUserId,
        ), $actorUserId);
    }
}
