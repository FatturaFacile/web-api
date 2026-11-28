<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use Illuminate\Support\Facades\DB;
use Modules\DairyCheeseManufacturing\Domain\Milk\Period;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Models\MonthlySettlement;

/**
 * Billing document of an approved milk settlement. The breeder is the supplier: the settlement becomes a purchase document
 * (self-billing / invoice TD01, credit note TD04) of the existing purchase invoice engine, with one line per priced component,
 * so payment, ledger posting and the SDI flow follow the normal accounts-payable path.
 */
final class SettlementDocumentService
{
    private const array DocumentTypes = ['self_billing' => 'TD01', 'invoice' => 'TD01', 'credit_note' => 'TD04'];

    public function create(MonthlySettlement $settlement, string $documentType): MonthlySettlement
    {
        if (! isset(self::DocumentTypes[$documentType])) {
            throw new DairyCheeseException('The document type is not valid.', 'document_type_invalid');
        }

        if ($settlement->status !== 'approved') {
            throw new DairyCheeseException('Approve the settlement before invoicing it.', 'settlement_not_approved');
        }

        $breeder = $settlement->breeder;

        if ($breeder->contact_id === null) {
            throw new DairyCheeseException('The breeder is not linked to a supplier contact.', 'breeder_contact_missing');
        }

        return DB::transaction(function () use ($settlement, $documentType, $breeder): MonthlySettlement {
            $period = Period::start((string) $settlement->period);
            $date = $period->endOfMonth();
            $number = sprintf('%s-%s-%s', config('dairy_cheese.document_prefix.'.$documentType), $settlement->period, $breeder->code);
            $vatRate = (float) $settlement->gross_amount > 0 ? round((float) $settlement->vat_amount / (float) $settlement->gross_amount * 100, 2) : 0.0;

            $invoice = PassiveInvoice::query()->create([
                'supplier_id' => $breeder->contact_id,
                'invoice_number' => $number,
                'date' => $date->toDateString(),
                'due_date' => $date->addDays((int) config('dairy_cheese.document_payment_terms_days', 30))->toDateString(),
                'total_net' => $settlement->gross_amount,
                'total_tax' => $settlement->vat_amount,
                'total_amount' => $settlement->net_amount,
                'currency' => $settlement->currency,
                'document_type' => self::DocumentTypes[$documentType],
                'payment_status' => 'unpaid',
            ]);

            foreach (array_values((array) $settlement->lines) as $i => $line) {
                $quantity = (float) $line['quantity_kg'];
                PassiveInvoiceLine::query()->create([
                    'passive_invoice_id' => $invoice->id,
                    'line_number' => $i + 1,
                    'description' => __('dairy_cheese.settlement_lines.'.$line['component']).' '.$settlement->period,
                    'quantity' => $quantity,
                    'unit_of_measure' => 'kg',
                    'unit_price' => $line['rate'],
                    'line_total' => $line['amount'],
                    'tax_rate' => $vatRate,
                    'competence_start_date' => $period->toDateString(),
                    'competence_end_date' => $date->toDateString(),
                ]);
            }

            $settlement->forceFill(['status' => 'invoiced', 'document_id' => $invoice->id])->save();

            return $settlement;
        });
    }
}
