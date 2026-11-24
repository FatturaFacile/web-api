<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Logistics\Enums\DeliveryNoteStatus;
use Modules\Logistics\Models\DeliveryNoteInvoiceLink;
use Modules\Logistics\Support\LogisticsMessage;

/**
 * Cumulative deferred invoice ("fattura differita", art. 21 c.4 lett. a DPR 633/72 and the equivalent Swiss practice):
 * several DDTs of the same customer and currency, delivered within the same calendar month, are billed on one
 * invoice. Every DDT line keeps a traceable link to the invoice line it was billed on.
 */
final class DeferredInvoicingService
{
    public function __construct(
        private readonly InvoicingServiceInterface $invoicing,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  list<string>  $deliveryNoteIds
     * @param  array<string, mixed>  $overrides  extra invoice header data (due_date, payment_method, ...)
     */
    public function invoice(array $deliveryNoteIds, array $overrides = [], bool $enforceSameMonth = true): SalesInvoice
    {
        $deliveryNoteIds = array_values(array_unique($deliveryNoteIds));
        if ($deliveryNoteIds === []) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.SELECT_DELIVERY_NOTE'));
        }

        return DB::transaction(function () use ($deliveryNoteIds, $overrides, $enforceSameMonth): SalesInvoice {
            $notes = SalesDeliveryNote::query()->with('items')->whereIn('id', $deliveryNoteIds)->orderBy('id')->lockForUpdate()->get();
            if ($notes->count() !== count($deliveryNoteIds)) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DELIVERY_NOTES_NOT_FOUND'));
            }

            $this->assertCompatible($notes->all(), $enforceSameMonth);

            $sortedNotes = $notes
                ->sortBy(static fn (SalesDeliveryNote $n): string => sprintf('%s|%s|%s', $n->date->format('Y-m-d'), (string) $n->ddt_number, (string) $n->id))
                ->values();

            $invoiceItems = [];
            foreach ($sortedNotes as $note) {
                foreach ($note->items as $item) {
                    $remaining = round((float) $item->quantity - (float) $item->invoiced_quantity, 4);
                    if ($remaining <= 0.0) {
                        continue;
                    }
                    $invoiceItems[] = [
                        'delivery_note_item_id' => (string) $item->id,
                        'sales_order_item_id' => $item->sales_order_item_id,
                        'product_id' => $item->product_id,
                        'description' => sprintf('DDT %s del %s - %s', $note->ddt_number, $note->date->format('d/m/Y'), $item->description),
                        'quantity' => $remaining,
                        'unit_price' => (float) $item->unit_price,
                        'tax_rate' => (float) $item->tax_rate,
                        'uom' => $item->uom,
                    ];
                }
            }

            if ($invoiceItems === []) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.ALREADY_INVOICED'));
            }

            /** @var SalesDeliveryNote $first */
            $first = $sortedNotes->first();
            $invoice = $this->invoicing->createInvoice(array_merge([
                'tenant_id' => (string) $this->tenantContext->getTenantId(),
                'contact_id' => (string) $first->contact_id,
                'currency' => $first->currency ?? 'EUR',
                'delivery_note_id' => $notes->count() === 1 ? (string) $first->id : null,
                'invoice_type' => 'standard',
                'notes' => 'Fattura differita - '.$sortedNotes->map(fn (SalesDeliveryNote $n): string => sprintf('DDT n. %s del %s', $n->ddt_number, $n->date->format('d/m/Y')))->implode('; '),
                'created_by' => auth()->id(),
            ], $overrides), $invoiceItems);

            $invoiceLineByDdtLine = $invoice->items->keyBy(fn ($line): string => (string) $line->delivery_note_item_id);

            foreach ($notes as $note) {
                foreach ($note->items as $item) {
                    $line = $invoiceLineByDdtLine->get((string) $item->id);
                    if ($line === null) {
                        continue;
                    }
                    DeliveryNoteInvoiceLink::create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $note->tenant_id,
                        'delivery_note_id' => $note->id,
                        'delivery_note_item_id' => $item->id,
                        'invoice_id' => $invoice->id,
                        'invoice_item_id' => $line->id,
                        'quantity' => (float) $line->quantity,
                    ]);
                    $item->update(['invoiced_quantity' => round((float) $item->invoiced_quantity + (float) $line->quantity, 4)]);
                }

                $note->load('items');
                $fully = $note->items->every(fn ($i): bool => (float) $i->invoiced_quantity >= (float) $i->quantity);
                $note->update(['status' => $fully ? DeliveryNoteStatus::Invoiced->value : DeliveryNoteStatus::PartiallyInvoiced->value]);
            }

            return $invoice->load('items');
        });
    }

    /**
     * @param  list<SalesDeliveryNote>  $notes
     */
    private function assertCompatible(array $notes, bool $enforceSameMonth): void
    {
        $customers = [];
        $currencies = [];
        $months = [];
        foreach ($notes as $note) {
            if (! in_array($note->status, DeliveryNoteStatus::invoiceable(), true)) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.NOT_INVOICEABLE', ['number' => $note->ddt_number, 'status' => $note->status]));
            }
            if (! $note->isBillable()) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.ON_APPROVAL_NOT_BILLABLE', ['number' => $note->ddt_number]));
            }
            if ($note->contact_id === null) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.NO_CUSTOMER_TO_INVOICE', ['number' => $note->ddt_number]));
            }
            $customers[(string) $note->contact_id] = true;
            $currencies[(string) ($note->currency ?? 'EUR')] = true;
            $months[$note->date->format('Y-m')] = true;
        }

        if (count($customers) > 1) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DDT_SAME_CUSTOMER'));
        }
        if (count($currencies) > 1) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DDT_SAME_CURRENCY'));
        }
        if ($enforceSameMonth && count($months) > 1) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DDT_SAME_MONTH'));
        }
    }
}
