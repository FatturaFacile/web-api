<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Logistics\Enums\DeliveryNoteStatus;
use Modules\Logistics\Support\LogisticsMessage;

/**
 * Deferred ("differita") billing of delivery notes: one cumulative invoice per customer / currency / calendar month,
 * each invoice line and the invoice header referencing the DDT number and date it bills.
 */
final class OrderBillingService
{
    public function __construct(
        private readonly DeferredInvoicingService $invoicing,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Issues one cumulative deferred invoice for the given delivery notes (same customer & currency).
     *
     * @param  list<string>  $deliveryNoteIds
     * @param  array<string, mixed>  $overrides
     */
    public function issueDeferredInvoice(array $deliveryNoteIds, array $overrides = [], bool $enforceSameMonth = true): SalesInvoice
    {
        return $this->invoicing->invoice($deliveryNoteIds, $overrides, $enforceSameMonth);
    }

    /**
     * Groups every unbilled issued / in-transit / delivered DDT of the period per customer, currency and month and
     * issues one deferred invoice per group.
     *
     * @return list<SalesInvoice>
     */
    public function runDeferredInvoicing(?Carbon $upTo = null, ?string $contactId = null): array
    {
        $upTo ??= now()->endOfMonth();

        $notes = SalesDeliveryNote::query()
            ->whereIn('status', DeliveryNoteStatus::invoiceable())
            ->billable()
            ->whereNotNull('contact_id')
            ->whereDate('date', '<=', $upTo->toDateString())
            ->when($contactId !== null, fn ($q) => $q->where('contact_id', $contactId))
            ->orderBy('date')
            ->orderBy('ddt_number')
            ->orderBy('id')
            ->get();

        $groups = [];
        foreach ($notes as $note) {
            $key = $note->contact_id.'|'.($note->currency ?? 'EUR').'|'.$note->date->format('Y-m');
            $groups[$key][] = (string) $note->id;
        }

        if ($groups !== [] && $this->tenantContext->getTenantId() === null) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.TENANT_REQUIRED'));
        }

        $invoices = [];
        foreach ($groups as $ids) {
            $invoices[] = $this->invoicing->invoice($ids);
        }

        return $invoices;
    }

    /**
     * Unbilled delivery notes grouped by customer, for the "bulk deferred invoice" screen.
     *
     * @return list<array{contact_id: string, currency: string, delivery_notes: list<array<string, mixed>>}>
     */
    public function unbilledByCustomer(): array
    {
        $groups = [];
        SalesDeliveryNote::query()
            ->whereIn('status', DeliveryNoteStatus::invoiceable())
            ->billable()
            ->whereNotNull('contact_id')
            ->orderBy('date')
            ->orderBy('ddt_number')
            ->orderBy('id')
            ->get()
            ->each(function (SalesDeliveryNote $note) use (&$groups): void {
                $key = $note->contact_id.'|'.($note->currency ?? 'EUR');
                $groups[$key] ??= ['contact_id' => (string) $note->contact_id, 'currency' => (string) ($note->currency ?? 'EUR'), 'delivery_notes' => []];
                $groups[$key]['delivery_notes'][] = [
                    'id' => (string) $note->id,
                    'ddt_number' => $note->ddt_number,
                    'date' => $note->date->toDateString(),
                    'status' => $note->status,
                ];
            });

        return array_values($groups);
    }
}
