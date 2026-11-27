<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Services;

use App\Models\BankStatementLine;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Modules\SwissEbill\Models\EbillTransmission;

/**
 * Closes the eBill lifecycle when the money arrives. A credit of the bank (camt.054 notification or camt.053 statement),
 * matched to an invoice by its QR / SCOR reference, EndToEndId or network transaction id, ends as a payment on the invoice;
 * this service reads that payment: a full settlement moves the active transmission to paid_reconciled (with the bank entry id,
 * the value date and the amount), a partial one is recorded on the timeline with the open difference. Whenever the credited total
 * differs from the amount of the eBill the discrepancy is flagged on the transmission.
 */
final class EbillSettlementReconciler
{
    private const float TOLERANCE = 0.005;

    public function __construct(
        private readonly EbillTransmissionRepositoryInterface $transmissions,
        private readonly EbillTransmissionLifecycle $lifecycle,
    ) {}

    public function reconcile(string $invoiceId, string $paymentId): ?EbillTransmission
    {
        $transmission = $this->transmissions->activeForInvoice($invoiceId);

        if ($transmission === null || ! $transmission->status->isInFlight()) {
            return null;
        }

        $invoice = Invoice::query()->find($invoiceId);
        $payment = Payment::query()->find($paymentId);

        if ($invoice === null || $payment === null || $payment->type !== 'payment') {
            return null;
        }

        $line = $payment->bank_statement_line_id !== null ? BankStatementLine::query()->find($payment->bank_statement_line_id) : null;
        $entryId = $line?->entry_reference ?: $payment->reference;
        $valueDate = $line !== null && $line->value_date !== null ? $line->value_date->toDateString() : Carbon::parse($payment->paid_at)->toDateString();
        $settled = round((float) $invoice->total_paid, 2);
        $expected = round((float) $transmission->invoice_amount, 2);
        $difference = round($expected - $settled, 2);

        if ($settled + self::TOLERANCE >= $expected) {
            return $this->lifecycle->transition(
                $transmission,
                EbillTransmissionStatus::PaidReconciled,
                EbillEventSource::Camt054,
                [
                    'camt054_entry_id' => $entryId !== null ? mb_substr((string) $entryId, 0, 120) : null,
                    'settlement_value_date' => $valueDate,
                    'settled_amount' => $settled,
                    // A credit above or below the eBill amount (bank charges, a credit note) is kept visible, not hidden.
                    'amount_discrepancy' => abs($difference) > self::TOLERANCE ? $difference : null,
                ],
                ['payment_id' => $payment->id, 'amount' => (float) $payment->amount, 'settled_total' => $settled, 'expected' => $expected, 'discrepancy' => $difference],
                null,
                Carbon::parse($valueDate),
            );
        }

        $transmission->forceFill(['settled_amount' => $settled, 'amount_discrepancy' => $difference, 'camt054_entry_id' => $entryId !== null ? mb_substr((string) $entryId, 0, 120) : $transmission->camt054_entry_id])->save();
        $transmission->appendEvent('partial_settlement', EbillEventSource::Camt054, null, null, [
            'payment_id' => $payment->id, 'amount' => (float) $payment->amount, 'settled_total' => $settled, 'open_difference' => $difference, 'entry_id' => $entryId,
        ], null, Carbon::parse($valueDate));

        return $transmission;
    }
}
