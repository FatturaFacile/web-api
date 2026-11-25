<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\IndustrialLaundry\Compliance\BillingContext;
use Modules\IndustrialLaundry\Compliance\BillingDecoration;
use Modules\IndustrialLaundry\Compliance\ComplianceStrategyRegistry;
use Modules\IndustrialLaundry\Domain\Enums\BillingLineKind;
use Modules\IndustrialLaundry\Domain\Enums\BillingRunStatus;
use Modules\IndustrialLaundry\Domain\Enums\GarmentEventType;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;
use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Models\BillingRun;
use Modules\IndustrialLaundry\Models\BillingRunLine;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\GarmentEvent;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Models\RollCage;
use Modules\IndustrialLaundry\Models\ScanSession;
use Modules\IndustrialLaundry\Support\Money;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;

/**
 * Consolidates the monthly bill of a customer: gathers what the tunnel gates recorded, runs the calculator, lets the compliance
 * strategies decorate the invoice (TD24 / QR-bill, delivery note references, returnable containers, water discharge), stores the
 * run and drafts the sales invoice.
 */
final class MonthlyBillingService
{
    public function __construct(
        private readonly BillingCalculator $calculator,
        private readonly ComplianceStrategyRegistry $compliance,
        private readonly InvoicingServiceInterface $invoicing,
        private readonly GarmentLifecycleService $lifecycle,
    ) {}

    /**
     * The bill of a period without storing anything (the dashboard preview).
     *
     * @return array<string, mixed>
     */
    public function preview(ParContract $contract, Carbon $start, Carbon $end): array
    {
        return $this->compute($contract, $start->copy()->startOfDay(), $end->copy()->endOfDay())['result'];
    }

    public function calculate(string $tenantId, ParContract $contract, Carbon $start, Carbon $end, bool $createInvoice, ?string $userId): BillingRun
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();
        $this->assertBillable($contract, $start, $end);

        return DB::transaction(function () use ($tenantId, $contract, $start, $end, $createInvoice, $userId): BillingRun {
            $computed = $this->compute($contract, $start, $end);
            $result = $computed['result'];

            if ($result['lines'] === []) {
                throw new IndustrialLaundryException('nothing_to_bill');
            }

            $run = BillingRun::query()->create([
                'tenant_id' => $tenantId,
                'contract_id' => $contract->id,
                'customer_id' => $contract->customer_id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => BillingRunStatus::Drafted->value,
                'billing_model' => $contract->billing_model->value,
                'jurisdiction' => $contract->jurisdiction->value,
                'currency' => $contract->currency,
                'washed_pieces' => $result['washed_pieces'],
                'net_weight_kg' => $result['net_weight_kg'],
                'wash_amount' => $result['wash_amount'],
                'minimum_top_up_amount' => $result['minimum_top_up_amount'],
                'rental_amount' => $result['rental_amount'],
                'unreturned_amount' => $result['unreturned_amount'],
                'subtotal' => $result['subtotal'],
                'tax_rate' => $result['tax_rate'],
                'tax_amount' => $result['tax_amount'],
                'total' => $result['total'],
                'compliance_report' => $result['compliance'],
                'created_by' => $userId,
            ]);

            foreach ($result['lines'] as $index => $line) {
                BillingRunLine::query()->create([
                    'tenant_id' => $tenantId,
                    'billing_run_id' => $run->id,
                    'position' => $index + 1,
                    'kind' => $line['kind']->value,
                    'article_type' => $line['article_type']?->value,
                    'quantity' => $line['quantity'],
                    'uom' => $line['uom'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'detail' => $line['detail'],
                    'created_by' => $userId,
                ]);
            }

            if ($createInvoice) {
                $this->invoice($run->refresh(), $contract, $computed['decoration'], $userId);
            }

            return $run->load('lines');
        });
    }

    /**
     * Drafts the sales invoice of a billing run that was stored without one.
     */
    public function issueInvoice(BillingRun $run, ?string $userId): BillingRun
    {
        if ($run->status === BillingRunStatus::Invoiced) {
            throw new IndustrialLaundryException('billing_run_already_invoiced');
        }

        return DB::transaction(function () use ($run, $userId): BillingRun {
            $contract = ParContract::query()->with('lines')->findOrFail($run->contract_id);
            $computed = $this->compute($contract, $run->period_start->copy()->startOfDay(), $run->period_end->copy()->endOfDay());
            $this->invoice($run, $contract, $computed['decoration'], $userId);

            return $run->refresh()->load('lines');
        });
    }

    /**
     * @return array{result: array<string, mixed>, decoration: BillingDecoration}
     */
    private function compute(ParContract $contract, Carbon $start, Carbon $end): array
    {
        $contract->loadMissing('lines');
        $facts = $this->facts($contract, $start, $end);
        $result = $this->calculator->calculate($contract, $facts['calculator'], $start, $end);
        $decoration = $this->compliance->decorateBilling(new BillingContext($contract, $start, $end, $facts['net_kg_total'], $result['washed_pieces'], $facts['delivery_notes'], $facts['roll_cages']));
        $result['compliance'] = $decoration->report;
        $result['document_type'] = $decoration->documentType;
        $result['payment_flavour'] = $decoration->paymentFlavour;
        $result['contract_id'] = (string) $contract->id;
        $result['customer_id'] = (string) $contract->customer_id;
        $result['period_start'] = $start->toDateString();
        $result['period_end'] = $end->toDateString();

        return ['result' => $result, 'decoration' => $decoration];
    }

    /**
     * @return array{calculator: array{delivered: list<array{article_type: string, at: Carbon}>, net_kg: string, active_garments: array<string, int>, unreturned: list<array{id: string, epc: string, article_type: string}>}, net_kg_total: string, delivery_notes: list<string>, roll_cages: array{out: int, back: int, at_customer: int}}
     */
    private function facts(ParContract $contract, Carbon $start, Carbon $end): array
    {
        $customerId = (string) $contract->customer_id;

        $delivered = GarmentEvent::query()
            ->where('contact_id', $customerId)
            ->where('event_type', GarmentEventType::DeliveredClean->value)
            ->whereBetween('occurred_at', [$start, $end])
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (GarmentEvent $event): array => ['article_type' => $event->article_type->value, 'at' => $event->occurred_at])
            ->values()
            ->all();

        $sessions = ScanSession::query()->where('contact_id', $customerId)->whereBetween('scanned_at', [$start, $end])->orderBy('scanned_at')->get();
        $billedDirection = $contract->kg_basis->direction();
        $netKg = Money::weight(Money::sum($sessions->filter(fn (ScanSession $session): bool => $session->direction === $billedDirection)->pluck('net_weight_kg')));
        $treatedKg = Money::weight(Money::sum($sessions->filter(fn (ScanSession $session): bool => $session->direction === ScanDirection::OutboundClean)->pluck('net_weight_kg')));

        $active = Garment::query()
            ->where('assigned_customer_id', $customerId)
            ->where('current_lifecycle_state', '!=', LifecycleState::CondemnedRag->value)
            ->get()
            ->countBy(fn (Garment $garment): string => $garment->article_type->value)
            ->all();

        $cutoff = $end->copy()->subDays($contract->unreturned_threshold_days)->startOfDay();
        $unreturned = Garment::query()
            ->where('assigned_customer_id', $customerId)
            ->where('current_lifecycle_state', LifecycleState::DeliveredToCustomer->value)
            ->whereNull('unreturned_billed_at')
            ->where('last_delivered_at', '<=', $cutoff)
            ->orderBy('epc_uid_rfid_hex')
            ->get()
            ->map(fn (Garment $garment): array => ['id' => (string) $garment->id, 'epc' => $garment->epc_uid_rfid_hex, 'article_type' => $garment->article_type->value])
            ->values()
            ->all();

        $cages = fn (ScanDirection $direction): int => $sessions->filter(fn (ScanSession $session): bool => $session->direction === $direction && $session->roll_cage_id !== null)->pluck('roll_cage_id')->unique()->count();

        return [
            'calculator' => ['delivered' => $delivered, 'net_kg' => $netKg, 'active_garments' => $active, 'unreturned' => $unreturned],
            'net_kg_total' => $treatedKg,
            'delivery_notes' => $sessions->pluck('generated_ddt_number')->filter()->unique()->sort()->values()->all(),
            'roll_cages' => ['out' => $cages(ScanDirection::OutboundClean), 'back' => $cages(ScanDirection::InboundSoil), 'at_customer' => RollCage::query()->where('holder_contact_id', $customerId)->count()],
        ];
    }

    private function assertBillable(ParContract $contract, Carbon $start, Carbon $end): void
    {
        if (! $contract->is_active) {
            throw new IndustrialLaundryException('contract_inactive');
        }

        if ($end->lessThan($start) || $start->diffInDays($end) > 62) {
            throw new IndustrialLaundryException('period_invalid');
        }

        if (! $contract->coversPeriod($start, $end)) {
            throw new IndustrialLaundryException('period_outside_contract');
        }

        $overlap = BillingRun::query()
            ->where('contract_id', $contract->id)
            ->whereDate('period_start', '<=', $end->toDateString())
            ->whereDate('period_end', '>=', $start->toDateString())
            ->exists();

        if ($overlap) {
            throw new IndustrialLaundryException('billing_period_already_billed');
        }
    }

    private function invoice(BillingRun $run, ParContract $contract, BillingDecoration $decoration, ?string $userId): void
    {
        $items = [];

        foreach ($run->lines()->get() as $line) {
            $items[] = [
                'description' => $this->description($line, $run),
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'tax_rate' => (string) $run->tax_rate,
            ];
        }

        $notes = array_map(static fn (array $note): string => __('industrial_laundry.invoice.'.$note['key'], $note['params']), $decoration->notes);

        $invoice = $this->invoicing->createInvoice(array_filter([
            'tenant_id' => (string) $run->tenant_id,
            'contact_id' => (string) $run->customer_id,
            'invoice_type' => 'standard',
            'document_type' => $decoration->documentType,
            'jurisdiction' => $contract->jurisdiction->value,
            'date' => $run->period_end->toDateString(),
            'currency' => $contract->currency,
            'notes' => implode("\n", [__('industrial_laundry.invoice.period', ['code' => $contract->code, 'from' => $run->period_start->toDateString(), 'to' => $run->period_end->toDateString()]), ...$notes]),
            'skip_charge_lines' => true,
            'created_by' => $userId,
        ], static fn (mixed $value): bool => $value !== null), $items);

        $run->forceFill(['status' => BillingRunStatus::Invoiced->value, 'invoice_id' => (string) $invoice->id, 'invoice_number' => (string) $invoice->invoice_number, 'updated_by' => $userId])->save();

        $this->closeUnreturned($run, $userId);
    }

    /**
     * Garments charged as unreturned are written off: condemned as rags, so they are billed once.
     */
    private function closeUnreturned(BillingRun $run, ?string $userId): void
    {
        $at = Carbon::now();

        foreach ($run->lines()->where('kind', BillingLineKind::Unreturned->value)->get() as $line) {
            foreach ((array) ($line->detail['garment_ids'] ?? []) as $garmentId) {
                $garment = Garment::query()->find($garmentId);

                if ($garment === null || $garment->current_lifecycle_state !== LifecycleState::DeliveredToCustomer) {
                    continue;
                }

                $garment->unreturned_billed_at = $at;
                $this->lifecycle->move($garment, LifecycleState::CondemnedRag, GarmentEventType::UnreturnedBilled, $at, ['reason' => 'unreturned_billed', 'note' => $run->invoice_number, 'userId' => $userId]);
            }
        }
    }

    private function description(BillingRunLine $line, BillingRun $run): string
    {
        $article = $line->article_type !== null ? __('industrial_laundry.article_types.'.$line->article_type->value) : '';

        return __('industrial_laundry.invoice.line.'.$line->kind->value, ['article' => $article, 'from' => $run->period_start->toDateString(), 'to' => $run->period_end->toDateString()]);
    }
}
