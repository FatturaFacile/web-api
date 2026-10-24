<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Normalises the different sales documents into one printable summary (number, date, totals, customer).
 */
class DocumentSummary
{
    /**
     * @return array{type: string, number: string, date: ?string, total: ?float, currency: ?string, contact_name: ?string, contact_email: ?string}
     */
    public function summarize(Model $document): array
    {
        $contact = $document instanceof WorkOrder ? $document->client : $document->contact;

        [$type, $number, $total, $currency] = match (true) {
            $document instanceof Invoice => ['invoice', $document->invoice_number, (float) $document->total_gross, $document->currency],
            $document instanceof Quote => ['quote', $document->quote_number, (float) $document->total_gross, $document->currency],
            $document instanceof SalesOrder => ['sales_order', $document->order_number, (float) $document->total_gross, $document->currency],
            $document instanceof DeliveryNote => ['delivery_note', $document->ddt_number, null, null],
            $document instanceof WorkOrder => ['work_order', $document->work_order_number, (float) $document->total_amount, null],
            default => ['document', (string) $document->getKey(), null, null],
        };

        return [
            'type' => $type,
            'number' => (string) $number,
            'date' => $document->date?->toDateString(),
            'total' => $total,
            'currency' => $currency,
            'contact_name' => $contact?->company_name ?: ($contact !== null ? trim($contact->first_name.' '.$contact->last_name) : null),
            'contact_email' => $contact?->email,
        ];
    }
}
