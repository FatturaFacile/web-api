<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;
use Modules\CreditManagement\Models\CreditCaseInvoice;
use Modules\CreditManagement\Models\CreditCollectionAction;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\CreditManagement\Models\CreditDispute;
use Modules\CreditManagement\Models\CreditDunningStep;
use Modules\CreditManagement\Models\CreditDunningStrategy;
use Modules\CreditManagement\Models\CreditPromiseToPay;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Collection cases. A case collects the overdue invoices of one customer and a collector works it along the steps of a
 * dunning strategy: a step can only run once the oldest invoice of the case is as many days overdue as the step asks
 * for. The status follows what is going on: an open dispute makes the case disputed, a pending promise to pay makes it
 * promised, an escalating step makes it escalated; the case is resolved when nothing is outstanding any more.
 */
final readonly class CollectionCaseService
{
    public function __construct(private ReceivablesLookupInterface $receivables) {}

    /**
     * @param  array{customer_id: string, invoice_ids?: list<string>|null, strategy_id?: string|null, collector_id?: string|null, priority?: string|null, notes?: string|null}  $data
     */
    public function open(string $tenantId, array $data): CreditCollectionCase
    {
        $customerId = (string) $data['customer_id'];

        if ($this->receivables->customerName($tenantId, $customerId) === null) {
            throw ValidationException::withMessages(['customer_id' => [__('credit_errors.customer.missing')]]);
        }

        $today = Carbon::today();
        $overdue = array_values(array_filter(
            $this->receivables->openInvoices($tenantId, $customerId),
            static fn (array $invoice): bool => ExposureService::daysOverdue($invoice['due_on'], $today) > 0
        ));
        $requested = $data['invoice_ids'] ?? null;

        if ($requested !== null && $requested !== []) {
            $known = array_column($overdue, 'invoice_id');

            if (array_diff($requested, $known) !== []) {
                throw ValidationException::withMessages(['invoice_ids' => [__('credit_errors.case.invoice_not_overdue')]]);
            }

            $overdue = array_values(array_filter($overdue, static fn (array $invoice): bool => in_array($invoice['invoice_id'], $requested, true)));
        }

        if ($overdue === []) {
            throw ValidationException::withMessages(['customer_id' => [__('credit_errors.case.no_overdue')]]);
        }

        $strategy = isset($data['strategy_id'])
            ? CreditDunningStrategy::query()->where('tenant_id', $tenantId)->where('is_active', true)->find($data['strategy_id'])
            : CreditDunningStrategy::query()->where('tenant_id', $tenantId)->where('is_active', true)->where('is_default', true)->first();

        if (isset($data['strategy_id']) && $strategy === null) {
            throw ValidationException::withMessages(['strategy_id' => [__('credit_errors.case.no_strategy')]]);
        }

        return DB::transaction(function () use ($tenantId, $customerId, $data, $overdue, $strategy, $today): CreditCollectionCase {
            TransactionLock::acquire('credit_case_number:'.$tenantId);

            $taken = CreditCaseInvoice::query()
                ->whereIn('invoice_id', array_column($overdue, 'invoice_id'))
                ->whereIn('case_id', CreditCollectionCase::query()->where('tenant_id', $tenantId)->whereNotIn('status', CreditCollectionCase::ClosedStatuses)->select('id'))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['invoice_ids' => [__('credit_errors.case.invoice_in_case')]]);
            }

            $outstanding = '0';
            $oldestDue = null;

            foreach ($overdue as $invoice) {
                $outstanding = Decimal::add($outstanding, $invoice['outstanding']);
                $oldestDue = $oldestDue === null || $invoice['due_on'] < $oldestDue ? $invoice['due_on'] : $oldestDue;
            }

            $first = $strategy?->steps()->first();
            $case = CreditCollectionCase::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'case_number' => $this->nextNumber($tenantId, $today),
                'status' => CreditCollectionCase::Open,
                'priority' => $data['priority'] ?? 'normal',
                'collector_id' => $data['collector_id'] ?? null,
                'strategy_id' => $strategy?->id,
                'current_step_order' => 0,
                'outstanding_amount' => Decimal::round2($outstanding),
                'currency' => (string) $overdue[0]['currency'],
                'opened_on' => $today->toDateString(),
                'next_action_on' => $first !== null && $oldestDue !== null ? Carbon::parse($oldestDue)->addDays($first->days_after_due)->toDateString() : null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($overdue as $invoice) {
                CreditCaseInvoice::create(['tenant_id' => $tenantId, 'case_id' => $case->id, 'invoice_id' => $invoice['invoice_id'], 'invoice_number' => $invoice['invoice_number'], 'due_on' => $invoice['due_on'], 'outstanding_amount' => $invoice['outstanding']]);
            }

            return $case->load(['invoices', 'strategy.steps']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CreditCollectionCase $case, array $data): CreditCollectionCase
    {
        $this->assertOpen($case);
        $case->update(array_intersect_key($data, array_flip(['priority', 'notes', 'collector_id', 'next_action_on'])));

        return $case->refresh();
    }

    /**
     * Runs the next dunning step of the case.
     */
    public function advance(CreditCollectionCase $case, ?string $userId, ?string $outcome): CreditCollectionCase
    {
        return DB::transaction(function () use ($case, $userId, $outcome): CreditCollectionCase {
            $case = CreditCollectionCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($case);

            if ($case->status === CreditCollectionCase::Disputed) {
                throw ValidationException::withMessages(['status' => [__('credit_errors.case.disputed')]]);
            }

            if ($case->promises()->where('status', CreditPromiseToPay::Pending)->exists()) {
                throw ValidationException::withMessages(['status' => [__('credit_errors.case.promise_pending')]]);
            }

            if ($case->strategy_id === null) {
                throw ValidationException::withMessages(['strategy_id' => [__('credit_errors.case.no_strategy')]]);
            }

            $step = CreditDunningStep::query()->where('strategy_id', $case->strategy_id)->where('step_order', '>', $case->current_step_order)->orderBy('step_order')->first();

            if ($step === null) {
                throw ValidationException::withMessages(['status' => [__('credit_errors.case.no_more_steps')]]);
            }

            $today = Carbon::today();
            $oldestDue = $this->oldestDue($case);
            $days = $oldestDue === null ? 0 : ExposureService::daysOverdue($oldestDue, $today);

            if ($days < $step->days_after_due) {
                throw ValidationException::withMessages(['status' => [__('credit_errors.case.step_not_due', ['days' => $step->days_after_due, 'overdue' => $days])]]);
            }

            CreditCollectionAction::create([
                'tenant_id' => $case->tenant_id,
                'case_id' => $case->id,
                'step_id' => $step->id,
                'action_type' => 'dunning_step',
                'channel' => $step->channel,
                'performed_on' => $today->toDateString(),
                'performed_by' => $userId,
                'fee_amount' => $step->fee_amount,
                'outcome' => $outcome,
            ]);

            $case->update(['current_step_order' => $step->step_order]);

            return $this->settle($case)->load(['invoices', 'strategy.steps', 'actions']);
        });
    }

    /**
     * @param  array{action_type: string, channel?: string|null, performed_on?: string|null, outcome?: string|null}  $data
     */
    public function addAction(CreditCollectionCase $case, ?string $userId, array $data): CreditCollectionAction
    {
        $this->assertOpen($case);

        return CreditCollectionAction::create([
            'tenant_id' => $case->tenant_id,
            'case_id' => $case->id,
            'action_type' => $data['action_type'],
            'channel' => $data['channel'] ?? null,
            'performed_on' => $data['performed_on'] ?? Carbon::today()->toDateString(),
            'performed_by' => $userId,
            'fee_amount' => '0.00',
            'outcome' => $data['outcome'] ?? null,
        ])->refresh();
    }

    public function assign(CreditCollectionCase $case, ?string $collectorId): CreditCollectionCase
    {
        $this->assertOpen($case);
        $case->update(['collector_id' => $collectorId]);

        return $case->refresh();
    }

    /**
     * Re-reads what is still owed on the invoices of the case. A case with nothing outstanding is resolved.
     */
    public function refresh(CreditCollectionCase $case): CreditCollectionCase
    {
        return DB::transaction(function () use ($case): CreditCollectionCase {
            $open = [];

            foreach ($this->receivables->openInvoices((string) $case->tenant_id, (string) $case->customer_id) as $invoice) {
                $open[$invoice['invoice_id']] = $invoice;
            }

            $outstanding = '0';

            foreach ($case->invoices()->get() as $row) {
                $amount = $open[$row->invoice_id]['outstanding'] ?? '0.00';
                $row->update(['outstanding_amount' => $amount]);
                $outstanding = Decimal::add($outstanding, $amount);
            }

            $case->update(['outstanding_amount' => Decimal::round2($outstanding)]);

            if (! $case->isClosed() && ! Decimal::isPositive($outstanding)) {
                $this->closeCase($case, CreditCollectionCase::Resolved);

                return $case->refresh();
            }

            return $this->settle($case);
        });
    }

    /**
     * Closes the case as resolved (nothing outstanding) or written off (the approver gives the debt up).
     */
    public function close(CreditCollectionCase $case, string $outcome, ?string $note): CreditCollectionCase
    {
        return DB::transaction(function () use ($case, $outcome, $note): CreditCollectionCase {
            $this->assertOpen($case);

            if ($case->disputes()->whereIn('status', CreditDispute::OpenStatuses)->exists()) {
                throw ValidationException::withMessages(['status' => [__('credit_errors.case.open_disputes')]]);
            }

            if ($outcome === CreditCollectionCase::Resolved) {
                $case = $this->refresh($case);

                if ($case->status !== CreditCollectionCase::Resolved) {
                    throw ValidationException::withMessages(['outcome' => [__('credit_errors.case.still_outstanding', ['amount' => $case->outstanding_amount])]]);
                }
            } else {
                $this->closeCase($case, CreditCollectionCase::WrittenOff);
            }

            if ($note !== null) {
                $case->update(['notes' => trim(($case->notes ?? '')."\n".$note)]);
            }

            return $case->refresh()->load(['invoices', 'strategy.steps', 'actions', 'promises', 'disputes']);
        });
    }

    /**
     * Sets the status of an open case from what is going on in it.
     */
    public function settle(CreditCollectionCase $case): CreditCollectionCase
    {
        if ($case->isClosed()) {
            return $case;
        }

        $step = $case->strategy_id !== null && $case->current_step_order > 0
            ? CreditDunningStep::query()->where('strategy_id', $case->strategy_id)->where('step_order', $case->current_step_order)->first()
            : null;
        $pending = $case->promises()->where('status', CreditPromiseToPay::Pending)->orderBy('promised_on')->first();

        $status = match (true) {
            $case->disputes()->whereIn('status', CreditDispute::OpenStatuses)->exists() => CreditCollectionCase::Disputed,
            $pending !== null => CreditCollectionCase::Promised,
            $step?->escalates === true => CreditCollectionCase::Escalated,
            $case->current_step_order > 0 => CreditCollectionCase::InDunning,
            default => CreditCollectionCase::Open,
        };

        $case->update(['status' => $status, 'next_action_on' => $pending !== null ? $pending->promised_on->copy()->addDay()->toDateString() : $this->nextActionOn($case)]);

        return $case->refresh();
    }

    private function nextActionOn(CreditCollectionCase $case): ?string
    {
        $oldestDue = $this->oldestDue($case);

        if ($case->strategy_id === null || $oldestDue === null) {
            return null;
        }

        $next = CreditDunningStep::query()->where('strategy_id', $case->strategy_id)->where('step_order', '>', $case->current_step_order)->orderBy('step_order')->first();

        return $next === null ? null : Carbon::parse($oldestDue)->addDays($next->days_after_due)->toDateString();
    }

    private function oldestDue(CreditCollectionCase $case): ?string
    {
        $due = $case->invoices()->where('outstanding_amount', '>', 0)->whereNotNull('due_on')->min('due_on');

        return $due === null ? null : substr((string) $due, 0, 10);
    }

    private function closeCase(CreditCollectionCase $case, string $status): void
    {
        $case->promises()->where('status', CreditPromiseToPay::Pending)->update(['status' => CreditPromiseToPay::Cancelled, 'resolved_on' => Carbon::today()->toDateString()]);
        $case->update(['status' => $status, 'closed_on' => Carbon::today()->toDateString(), 'next_action_on' => null]);
    }

    private function assertOpen(CreditCollectionCase $case): void
    {
        if ($case->isClosed()) {
            throw ValidationException::withMessages(['status' => [__('credit_errors.case.closed')]]);
        }
    }

    private function nextNumber(string $tenantId, Carbon $today): string
    {
        $prefix = 'CC-'.$today->format('Y').'-';
        $last = CreditCollectionCase::withTrashed()->where('tenant_id', $tenantId)->where('case_number', 'like', $prefix.'%')->orderByDesc('case_number')->value('case_number');
        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
