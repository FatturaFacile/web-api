<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\Events\TicketTimeBillable;
use Modules\ServiceManagement\Exceptions\HelpdeskRuleViolation;
use Modules\ServiceManagement\Exceptions\HourBankException;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketTimeEntry;
use Modules\ServiceManagement\Services\HourBankService;
use Modules\ServiceManagement\Services\ServiceAmounts;

/**
 * Time logged on a ticket: deducted from the prepaid hour bank of the covering service contract and, for what the bank
 * cannot cover (no contract, flat rate, depleted bank), left as billable time and materials for the billing side.
 *
 * The covering contract is the one on the ticket, else the active prepaid contract of the customer. A bank that covers
 * only part of the time is emptied and the rest is billable.
 */
final readonly class TicketTimeLogger
{
    public const string BillingNone = 'none';

    public const string BillingContractDeducted = 'contract_deducted';

    public const string BillingPartiallyDeducted = 'partially_deducted';

    public const string BillingPending = 'billable_pending';

    public const string BillingNonBillable = 'non_billable';

    public function __construct(
        private TicketServiceInterface $tickets,
        private HourBankService $hourBank,
        private TicketActivityLog $activityLog,
    ) {}

    public function log(Ticket $ticket, int $minutes, ?string $technicianId = null, ?string $description = null, bool $billable = true, ?string $actorUserId = null, ?string $idempotencyKey = null): TicketTimeEntry
    {
        return DB::transaction(function () use ($ticket, $minutes, $technicianId, $description, $billable, $actorUserId, $idempotencyKey): TicketTimeEntry {
            if ($idempotencyKey !== null) {
                // A replay of a queued log (lost response, offline client) returns the entry written the first time.
                $existing = TicketTimeEntry::query()->where('ticket_id', $ticket->id)->get()
                    ->first(static fn (TicketTimeEntry $entry): bool => ($entry->metadata['idempotency_key'] ?? null) === $idempotencyKey);

                if ($existing !== null) {
                    return $existing;
                }
            }

            $technicianId ??= $ticket->assigned_technician_id;

            if ($technicianId === null) {
                throw new HelpdeskRuleViolation('Time can be logged only for a technician: state one or assign the ticket.');
            }

            $hours = bcdiv((string) $minutes, '60', 2);
            $entry = $this->tickets->logTime($ticket->id, $hours, $technicianId, $description, $billable, null, $actorUserId);

            $deducted = '0.00';
            $contract = $billable ? $this->coveringContract($ticket) : null;

            if ($contract !== null) {
                $deducted = $this->deduct($contract, $entry, $hours, $actorUserId);
            }

            $billableHours = $billable ? bcsub($hours, $deducted, 2) : '0.00';

            $entry->service_contract_id = $contract?->id;
            $entry->contract_hours = $deducted;
            $entry->billable_hours = $billableHours;
            $entry->billing_status = match (true) {
                ! $billable => self::BillingNonBillable,
                bccomp($billableHours, '0', 2) === 0 => self::BillingContractDeducted,
                bccomp($deducted, '0', 2) > 0 => self::BillingPartiallyDeducted,
                default => self::BillingPending,
            };
            // Hours the contract covers are not charged again.
            $entry->total_charge = ServiceAmounts::lineTotal($billableHours, $entry->hourly_rate);
            if ($idempotencyKey !== null) {
                $entry->metadata = [...(array) $entry->metadata, 'idempotency_key' => $idempotencyKey];
            }

            $entry->save();

            $this->activityLog->record($ticket, 'time_logged', null, $entry->billing_status, [
                'time_entry_id' => $entry->id,
                'hours' => $hours,
                'contract_hours' => $deducted,
                'billable_hours' => $billableHours,
                'service_contract_id' => $contract?->id,
            ], $actorUserId);

            if (bccomp($billableHours, '0', 2) > 0) {
                event(new TicketTimeBillable(
                    tenantId: (string) $ticket->tenant_id,
                    ticketId: $ticket->id,
                    timeEntryId: $entry->id,
                    customerId: (string) $ticket->customer_id,
                    billableHours: $billableHours,
                    hourlyRate: ServiceAmounts::money($entry->hourly_rate),
                    totalCharge: ServiceAmounts::money($entry->total_charge),
                ));
            }

            return $entry->refresh();
        });
    }

    private function coveringContract(Ticket $ticket): ?ServiceContract
    {
        $today = now()->toDateString();
        $query = ServiceContract::query()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('status', 'active')
            ->where('included_hours', '>', 0)
            ->whereDate('start_date', '<=', $today)
            ->where(fn ($window) => $window->whereNull('end_date')->orWhereDate('end_date', '>=', $today));

        if ($ticket->service_contract_id !== null) {
            return (clone $query)->whereKey($ticket->service_contract_id)->first();
        }

        return $query->where('customer_id', $ticket->customer_id)->orderBy('created_at')->first();
    }

    /**
     * Books as many hours as the bank holds, up to the time logged. Returns the hours deducted.
     */
    private function deduct(ServiceContract $contract, TicketTimeEntry $entry, string $hours, ?string $actorUserId): string
    {
        $remaining = $contract->remainingHours();
        $deductible = bccomp($remaining, $hours, 2) < 0 ? $remaining : $hours;

        if (bccomp($deductible, '0', 2) <= 0) {
            return '0.00';
        }

        try {
            $transaction = $this->hourBank->deduct($contract, $deductible, [
                'ticket_id' => $entry->ticket_id,
                'reference' => 'ticket-time:'.$entry->id,
                'description' => $entry->description,
            ], $actorUserId);
        } catch (HourBankException) {
            // Lost the race for the last hours (or the contract is no longer active): the time is billable instead.
            return '0.00';
        }

        $entry->contract_transaction_id = $transaction->id;

        return $deductible;
    }
}
