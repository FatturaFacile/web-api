<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Infrastructure;

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Modules\AdventureParks\Contracts\SchoolInvoicePort;
use Modules\AdventureParks\Domain\ValueObjects\InvoiceReference;
use Modules\AdventureParks\Models\SchoolBooking;
use Modules\Invoicing\Services\InvoicingService;

/**
 * Creates the draft invoice of a school booking with the invoicing module: Italian sale with split payment (the FatturaPA builder
 * writes EsigibilitaIVA = S), the IPA office code as destination code of the school, CIG and CUP in DatiOrdineAcquisto.
 */
final class InvoicingSchoolInvoiceAdapter implements SchoolInvoicePort
{
    public function __construct(
        private readonly InvoicingService $invoicing,
        private readonly TenantContext $tenants,
    ) {}

    public function createDraft(SchoolBooking $booking, array $lines, string $wording, ?string $userId): InvoiceReference
    {
        $contact = $this->contact($booking);
        $invoice = $this->invoicing->createInvoice([
            'tenant_id' => (string) $this->tenants->getTenantId(),
            'contact_id' => (string) $contact->id,
            'jurisdiction' => 'IT',
            'currency' => 'EUR',
            'split_payment' => $booking->split_payment,
            'skip_charge_lines' => true,
            'notes' => $wording,
            'created_by' => $userId,
        ], $lines);

        $order = array_filter([
            'IdDocumento' => mb_substr((string) ($booking->mechanographic_code ?: substr((string) $booking->id, 0, 8)), 0, 20),
            'Data' => $booking->visit_date->toDateString(),
            'CodiceCUP' => $booking->cup_code,
            'CodiceCIG' => $booking->cig_code,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $invoice->sdi_extra = json_encode([
            'FatturaElettronicaBody' => ['DatiGenerali' => ['DatiOrdineAcquisto' => [$order]]],
        ], JSON_THROW_ON_ERROR);
        $invoice->save();

        return new InvoiceReference((string) $invoice->id, (string) $invoice->invoice_number);
    }

    /**
     * The school as a customer: the contact the booking points to, or a new one created from the booking. The destination code of
     * an invoice to the public administration is the 6-character IPA office code (Codice Univoco Ufficio).
     */
    private function contact(SchoolBooking $booking): Contact
    {
        $contact = $booking->customer_contact_id !== null ? Contact::query()->find($booking->customer_contact_id) : null;

        if ($contact === null) {
            $contact = Contact::query()->create([
                'type' => 'customer',
                'country' => 'IT',
                'company_name' => $booking->institute_name,
                'sdi_code' => $booking->ipa_office_code,
            ]);
            $booking->customer_contact_id = (string) $contact->id;
            $booking->save();

            return $contact;
        }

        if ($contact->sdi_code !== $booking->ipa_office_code) {
            $contact->sdi_code = $booking->ipa_office_code;
            $contact->save();
        }

        return $contact;
    }
}
