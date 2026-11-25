<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\Invoicing\Services\InvoicingService;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\Jurisdiction;
use Modules\PassengerTransport\Domain\Support\Dec;
use Modules\PassengerTransport\Domain\ValueObjects\VatBreakdown;
use Modules\PassengerTransport\Domain\ValueObjects\VatInput;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Models\SchoolBusBilling;
use Modules\PassengerTransport\Models\SchoolBusContract;

/**
 * Turns completed bookings into sales invoices in the invoicing module. Every booking becomes one or more lines, one per
 * territory of its VAT split (rate, natura code and legal reference included); the month-end run groups the bookings of a customer
 * (hotel, agency, corporate account, municipality) into one consolidated invoice, adding the monthly flat amount of its school bus
 * contracts once per month.
 */
final class BookingInvoicingService
{
    public function __construct(
        private readonly InvoicingService $invoicing,
        private readonly RegulatoryStrategyResolver $strategies,
        private readonly BookingService $bookings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function invoiceBooking(PassengerBooking $booking, ?string $userId): SalesInvoice
    {
        if ($booking->ordering_customer_id === null) {
            throw new PassengerTransportException('invoice_requires_customer', 422);
        }

        return $this->createInvoice((string) $booking->ordering_customer_id, new Collection([$booking]), new Collection, $userId);
    }

    /**
     * What the month-end run would invoice, by customer.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(string $period, ?string $contactId = null): array
    {
        $rows = [];

        foreach ($this->customerGroups($period, $contactId) as $customerId => $group) {
            $rows[] = $this->summary($customerId, $group['bookings'], $group['flats']);
        }

        return $rows;
    }

    /**
     * @param  list<string>|null  $contactIds  customers to invoice; null invoices every customer with something to bill
     * @return list<array<string, mixed>>
     */
    public function invoiceMonth(string $period, ?array $contactIds, ?string $userId): array
    {
        $created = [];

        foreach ($this->customerGroups($period) as $customerId => $group) {
            if ($contactIds !== null && ! in_array($customerId, $contactIds, true)) {
                continue;
            }

            $invoice = $this->createInvoice($customerId, $group['bookings'], $group['flats'], $userId, $period);
            $created[] = ['customer_id' => $customerId, 'invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'bookings_count' => $group['bookings']->count(), 'total_gross' => (string) $invoice->total_gross];
        }

        return $created;
    }

    /**
     * The customers with something to invoice in the month: completed bookings, the monthly flat amount of a school bus contract not
     * yet invoiced for it, or both.
     *
     * @return array<string, array{bookings: Collection<int, PassengerBooking>, flats: Collection<int, SchoolBusContract>}>
     */
    private function customerGroups(string $period, ?string $contactId = null): array
    {
        $flats = $this->flatContracts($period, $contactId)->groupBy('contact_id');
        $groups = [];

        foreach ($this->billableBookings($period, $contactId)->groupBy('ordering_customer_id') as $customerId => $bookings) {
            $groups[(string) $customerId] = ['bookings' => $bookings->values(), 'flats' => ($flats->get($customerId) ?? new Collection)->values()];
        }

        foreach ($flats as $customerId => $contracts) {
            $groups[(string) $customerId] ??= ['bookings' => new Collection, 'flats' => $contracts->values()];
        }

        return $groups;
    }

    /**
     * @return Collection<int, PassengerBooking>
     */
    private function billableBookings(string $period, ?string $contactId = null): Collection
    {
        [$from, $to] = $this->monthBounds($period);

        return PassengerBooking::query()
            ->where('status', BookingStatus::Completed->value)
            ->whereNull('invoice_id')
            ->whereNotNull('ordering_customer_id')
            ->where('exchange_direction', '!=', 'acquired')
            ->when($contactId, fn ($query, $id) => $query->where('ordering_customer_id', $id))
            ->whereBetween('pickup_datetime', [$from, $to])
            ->orderBy('pickup_datetime')
            ->get();
    }

    /**
     * The active school bus contracts with a flat amount that cover the month and are not yet invoiced for it.
     *
     * @return Collection<int, SchoolBusContract>
     */
    private function flatContracts(string $period, ?string $contactId = null): Collection
    {
        [$from, $to] = $this->monthBounds($period);

        return SchoolBusContract::query()
            ->where('is_active', true)
            ->whereNotNull('monthly_flat_amount')
            ->when($contactId, fn ($query, $id) => $query->where('contact_id', $id))
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $to->toDateString()))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from->toDateString()))
            ->whereNotIn('id', SchoolBusBilling::query()->where('period', $period)->select('school_contract_id'))
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  Collection<int, PassengerBooking>  $bookings
     * @param  Collection<int, SchoolBusContract>  $flats
     * @return array<string, mixed>
     */
    private function summary(string $customerId, Collection $bookings, Collection $flats): array
    {
        $net = '0';
        $tax = '0';
        $commission = '0';
        $flatAmount = '0';

        foreach ($bookings as $booking) {
            $breakdown = $this->breakdown($booking);
            $net = Dec::add($net, $breakdown->totalNet());
            $tax = Dec::add($tax, $breakdown->totalTax());
            $commission = Dec::add($commission, $booking->hotel_concierge_commission_amount);
        }

        foreach ($flats as $flat) {
            $breakdown = $this->flatBreakdown($flat);
            $net = Dec::add($net, $breakdown->totalNet());
            $tax = Dec::add($tax, $breakdown->totalTax());
            $flatAmount = Dec::add($flatAmount, $flat->monthly_flat_amount);
        }

        return [
            'customer_id' => $customerId,
            'customer_name' => Contact::query()->find($customerId)?->company_name,
            'currency' => $bookings->first()->currency ?? $flats->first()->currency,
            'bookings_count' => $bookings->count(),
            'booking_ids' => $bookings->pluck('id')->values()->all(),
            'booking_codes' => $bookings->pluck('booking_code')->values()->all(),
            'monthly_flat_amount' => $flats->isEmpty() ? null : Dec::round($flatAmount),
            'total_net' => Dec::round($net),
            'total_tax' => Dec::round($tax),
            'total_gross' => Dec::round(Dec::add($net, $tax)),
            'concierge_commission' => Dec::round($commission),
        ];
    }

    /**
     * @param  Collection<int, PassengerBooking>  $bookings
     * @param  Collection<int, SchoolBusContract>  $flats
     */
    private function createInvoice(string $customerId, Collection $bookings, Collection $flats, ?string $userId, ?string $period = null): SalesInvoice
    {
        foreach ($bookings as $booking) {
            if ($booking->status !== BookingStatus::Completed) {
                throw new PassengerTransportException('invoice_booking_not_completed', 409, ['booking' => $booking->booking_code]);
            }

            if ($booking->invoice_id !== null) {
                throw new PassengerTransportException('invoice_booking_already_invoiced', 409, ['booking' => $booking->booking_code]);
            }
        }

        $currencies = $bookings->pluck('currency')->merge($flats->pluck('currency'))->unique();

        if ($currencies->count() > 1) {
            throw new PassengerTransportException('invoice_mixed_currencies', 422);
        }

        $tenant = $this->tenantContext->getTenant();
        $jurisdiction = $this->strategies->jurisdictionOfCurrentTenant();
        $items = [];
        $natures = [];

        foreach ($bookings as $booking) {
            foreach ($this->breakdown($booking)->lines as $line) {
                $description = (string) __('passenger_transport.invoice.line_'.$line->kind->value, ['booking' => $booking->booking_code, 'route' => $booking->pickup_address.' → '.$booking->dropoff_address, 'km' => $line->km, 'date' => $booking->pickup_datetime->format('d/m/Y')]);
                $items[] = ['description' => $description, 'quantity' => 1, 'unit_price' => $line->net, 'tax_rate' => (float) $line->rate, 'notes' => $line->legalReference.($line->nature !== null ? ' - '.$line->nature : '')];

                if ($line->nature !== null) {
                    $natures[$description] = $line->nature;
                }
            }
        }

        foreach ($flats as $flat) {
            foreach ($this->flatBreakdown($flat)->lines as $line) {
                $description = (string) __('passenger_transport.invoice.line_monthly_flat', ['contract' => $flat->code, 'period' => $period ?? '']);
                $items[] = ['description' => $description, 'quantity' => 1, 'unit_price' => $line->net, 'tax_rate' => (float) $line->rate, 'notes' => $line->legalReference];
            }
        }

        return DB::transaction(function () use ($customerId, $bookings, $flats, $items, $natures, $tenant, $jurisdiction, $currencies, $userId, $period): SalesInvoice {
            $invoice = $this->invoicing->createInvoice([
                'tenant_id' => $tenant?->id,
                'contact_id' => $customerId,
                'invoice_type' => 'standard',
                'currency' => (string) ($currencies->first() ?? $tenant->default_currency ?? 'EUR'),
                'jurisdiction' => $jurisdiction === Jurisdiction::Ch ? 'CH' : 'IT',
                'notes' => (string) __('passenger_transport.invoice.notes', ['bookings' => $bookings->pluck('booking_code')->implode(', '), 'period' => $period ?? '']),
                'created_by' => $userId,
            ], $items);

            foreach ($natures as $description => $nature) {
                SalesInvoiceItem::query()->where('invoice_id', $invoice->id)->where('description', $description)->update(['natura' => $nature]);
            }

            foreach ($bookings as $booking) {
                $booking->update(['invoice_id' => $invoice->id, 'status' => BookingStatus::Invoiced->value, 'updated_by' => $userId]);
            }

            foreach ($flats as $flat) {
                SchoolBusBilling::query()->create(['tenant_id' => $tenant?->id, 'school_contract_id' => $flat->id, 'period' => $period, 'invoice_id' => $invoice->id, 'created_by' => $userId]);
            }

            return $invoice;
        });
    }

    private function breakdown(PassengerBooking $booking): VatBreakdown
    {
        return $this->strategies->forCurrentTenant()->computeVat($this->bookings->vatInput($booking->attributesToArray()));
    }

    private function flatBreakdown(SchoolBusContract $contract): VatBreakdown
    {
        return $this->strategies->forCurrentTenant()->computeVat(new VatInput((string) $contract->monthly_flat_amount, $contract->currency, '0', '0', '0', '0'));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthBounds(string $period): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new PassengerTransportException('billing_period_invalid', 422);
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay();

        return [$start, $start->endOfMonth()];
    }
}
