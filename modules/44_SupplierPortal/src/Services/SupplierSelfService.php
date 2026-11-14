<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\PassiveInvoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\RfqLine;
use Modules\Procurement\Models\RfqSupplier;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierQuote;
use Modules\Procurement\Services\RfqService;
use Modules\SupplierPortal\Enums\OnboardingStatus;
use Modules\SupplierPortal\Models\SupplierOnboarding;

/**
 * What a supplier sees and does on its portal, always limited to its own records: its purchase orders (from the moment
 * they are sent), the requests for quotation it was invited to (never the quotes of the other suppliers) with quote
 * submission until the deadline, and the status of the invoices it sent. A supplier whose onboarding was rejected or
 * blocked cannot quote.
 */
final readonly class SupplierSelfService
{
    /**
     * Purchase order statuses a supplier may see: nothing before the order is sent.
     *
     * @var list<string>
     */
    public const array VisibleOrderStatuses = ['sent', 'partially_received', 'received', 'closed', 'cancelled'];

    public function __construct(private RfqService $rfqs, private SupplierPortalLedger $ledger) {}

    /**
     * @return Collection<int, PurchaseOrder>
     */
    public function purchaseOrders(Supplier $supplier): Collection
    {
        return PurchaseOrder::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', self::VisibleOrderStatuses)
            ->orderByDesc('date')
            ->limit(200)
            ->get();
    }

    public function purchaseOrder(Supplier $supplier, string $orderId): PurchaseOrder
    {
        return PurchaseOrder::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', self::VisibleOrderStatuses)
            ->whereKey($orderId)
            ->firstOrFail();
    }

    /**
     * @return Collection<int, PurchaseOrderLine>
     */
    public function orderLines(PurchaseOrder $order): Collection
    {
        return PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->orderBy('line_number')->get();
    }

    /**
     * Requests the supplier was invited to, newest deadline first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rfqs(Supplier $supplier): Collection
    {
        $invitations = RfqSupplier::query()->where('supplier_id', $supplier->id)->get()->keyBy('rfq_id');

        return Rfq::query()
            ->whereIn('id', $invitations->keys())
            ->whereIn('status', ['issued', 'awarded', 'cancelled'])
            ->orderByDesc('response_deadline')
            ->get()
            ->map(fn (Rfq $rfq): array => $this->rfqSummary($rfq, $invitations->get($rfq->id), $supplier));
    }

    /**
     * @return array<string, mixed>
     */
    public function rfq(Supplier $supplier, string $rfqId): array
    {
        $invitation = RfqSupplier::query()->where('supplier_id', $supplier->id)->where('rfq_id', $rfqId)->firstOrFail();
        $rfq = Rfq::query()->whereKey($rfqId)->whereIn('status', ['issued', 'awarded', 'cancelled'])->firstOrFail();

        return [
            ...$this->rfqSummary($rfq, $invitation, $supplier),
            'notes' => $rfq->notes,
            'lines' => $rfq->lines->map(static fn (RfqLine $line): array => [
                'id' => (string) $line->id,
                'line_number' => (int) $line->line_number,
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'uom' => (string) $line->uom,
                'required_by_date' => $line->required_by_date?->toDateString(),
            ])->all(),
            'quote' => $this->ownQuote($rfq, $supplier),
        ];
    }

    /**
     * Submits the quote of the supplier, once, by the response deadline (checked by the procurement module).
     *
     * @param  array{quote_reference?: ?string, valid_until?: ?string, lead_time_days?: ?int, notes?: ?string, lines: list<array{rfq_line_id: string, unit_price: numeric-string|float|int, quantity?: numeric-string|float|int|null, tax_rate?: numeric-string|float|int|null, lead_time_days?: ?int}>}  $data
     * @return array<string, mixed>
     *
     * @throws BusinessRuleException
     */
    public function submitQuote(Supplier $supplier, string $rfqId, array $data): array
    {
        $this->assertCanQuote($supplier);
        $rfq = Rfq::query()->whereKey($rfqId)->firstOrFail();
        RfqSupplier::query()->where('rfq_id', $rfq->id)->where('supplier_id', $supplier->id)->firstOrFail();

        $quote = $this->rfqs->recordQuote($rfq, [...$data, 'supplier_id' => (string) $supplier->id, 'quote_date' => now()->toDateString()]);
        $this->ledger->record('SUPPLIER_QUOTE_SUBMITTED_FROM_PORTAL', $quote, ['supplier_id' => $supplier->id, 'rfq_id' => $rfq->id, 'total_amount' => (string) $quote->total_amount]);

        return $this->ownQuote($rfq, $supplier) ?? [];
    }

    /**
     * The supplier declines to quote.
     *
     * @throws BusinessRuleException
     */
    public function decline(Supplier $supplier, string $rfqId): void
    {
        DB::transaction(function () use ($supplier, $rfqId): void {
            $rfq = Rfq::query()->whereKey($rfqId)->lockForUpdate()->firstOrFail();
            $invitation = RfqSupplier::query()->where('rfq_id', $rfq->id)->where('supplier_id', $supplier->id)->lockForUpdate()->firstOrFail();

            if ($rfq->status !== 'issued' || $invitation->status !== 'invited') {
                throw new BusinessRuleException('This request can no longer be declined.');
            }

            $invitation->update(['status' => 'declined', 'responded_at' => now()]);
            $this->ledger->record('SUPPLIER_RFQ_DECLINED_FROM_PORTAL', $invitation, ['supplier_id' => $supplier->id, 'rfq_id' => $rfq->id]);
        });
    }

    /**
     * Invoices of the supplier with the status it cares about: received, in approval, approved, on hold, rejected, and the
     * payment status. Approval internals (workflow, cost centre) stay inside.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function invoices(Supplier $supplier): Collection
    {
        return PassiveInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('date')
            ->limit(200)
            ->get()
            ->map(static fn (PassiveInvoice $invoice): array => [
                'id' => (string) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'document_type' => $invoice->document_type,
                'date' => $invoice->date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total_amount' => (string) $invoice->total_amount,
                'total_paid' => (string) ($invoice->total_paid ?? '0.00'),
                'currency' => (string) ($invoice->currency ?? 'EUR'),
                'processing_status' => match (true) {
                    $invoice->approval_status === PassiveInvoice::MatchBlockedStatus => 'on_hold',
                    $invoice->approval_status === 'rejected' => 'rejected',
                    $invoice->approval_status === 'approved' => 'approved',
                    in_array($invoice->approval_status, ['pending', 'in_approval'], true) => 'in_approval',
                    default => 'received',
                },
                'payment_status' => $invoice->payment_status,
            ]);
    }

    /**
     * The onboarding of the supplier, if any.
     */
    public function onboardingOf(Supplier $supplier): ?SupplierOnboarding
    {
        return SupplierOnboarding::query()->where('supplier_id', $supplier->id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function rfqSummary(Rfq $rfq, ?RfqSupplier $invitation, Supplier $supplier): array
    {
        $open = $rfq->status === 'issued' && $rfq->response_deadline->toDateString() >= now()->toDateString();

        return [
            'id' => (string) $rfq->id,
            'rfq_number' => (string) $rfq->rfq_number,
            'title' => (string) $rfq->title,
            'status' => (string) $rfq->status,
            'currency' => (string) $rfq->currency,
            'response_deadline' => $rfq->response_deadline->toDateString(),
            'invitation_status' => $invitation?->status,
            'can_quote' => $open && $invitation?->status === 'invited' && $this->canQuote($supplier),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ownQuote(Rfq $rfq, Supplier $supplier): ?array
    {
        $quote = SupplierQuote::query()->with('lines')->where('rfq_id', $rfq->id)->where('supplier_id', $supplier->id)->first();

        return $quote === null ? null : [
            'id' => (string) $quote->id,
            'quote_reference' => $quote->quote_reference,
            'quote_date' => $quote->quote_date->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'lead_time_days' => $quote->lead_time_days,
            'total_amount' => (string) $quote->total_amount,
            'currency' => (string) $quote->currency,
            'status' => (string) $quote->status,
            'lines' => $quote->lines->map(static fn ($line): array => [
                'rfq_line_id' => (string) $line->rfq_line_id,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'tax_rate' => (string) $line->tax_rate,
                'line_total' => (string) $line->line_total,
                'lead_time_days' => $line->lead_time_days,
            ])->all(),
        ];
    }

    private function canQuote(Supplier $supplier): bool
    {
        $onboarding = $this->onboardingOf($supplier);

        return $onboarding === null || ! in_array($onboarding->statusEnum(), [OnboardingStatus::Rejected, OnboardingStatus::Blocked], true);
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertCanQuote(Supplier $supplier): void
    {
        if (! $this->canQuote($supplier)) {
            throw new BusinessRuleException('This supplier cannot submit quotes.');
        }
    }
}
