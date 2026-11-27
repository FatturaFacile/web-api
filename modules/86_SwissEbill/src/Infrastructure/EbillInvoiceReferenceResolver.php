<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure;

use App\Contracts\Banking\InvoiceReferenceResolverInterface;
use App\Models\Invoice;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Models\EbillTransmission;

/**
 * Recognises a bank entry of an eBill by the EndToEndId (our idempotency key, which the network passes to the payment) or by the
 * SIX transaction id in the references of the camt.054 entry, when the QR / SCOR reference did not match.
 */
final class EbillInvoiceReferenceResolver implements InvoiceReferenceResolverInterface
{
    public function resolve(string $text): ?Invoice
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $inFlight = EbillTransmission::query()
            ->whereIn('status', array_map(static fn (EbillTransmissionStatus $s): string => $s->value, EbillTransmissionStatus::inFlight()))
            ->get(['id', 'invoice_id', 'idempotency_key', 'six_transaction_id']);

        $match = $inFlight->first(static fn (EbillTransmission $t): bool => str_contains($text, $t->idempotency_key)
            || ($t->six_transaction_id !== null && $t->six_transaction_id !== '' && str_contains($text, $t->six_transaction_id)));

        return $match === null ? null : Invoice::query()->find($match->invoice_id);
    }
}
