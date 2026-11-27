<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\PaymentFlows;

use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;

/**
 * Stops the same supplier invoice, or the same end-to-end identifier, from reaching two payment files. A line counts
 * as taken while it is waiting for the bank or already executed; lines the bank rejected, or of a cancelled batch,
 * free the invoice again so it can be paid in a new run.
 */
final class DuplicatePaymentGuard
{
    /**
     * Statuses of a payment line that still hold the invoice.
     *
     * @var list<string>
     */
    public const array HOLDING_STATUSES = ['pending', 'executed', 'cleared', 'reconciled'];

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{path: string, code: string, params: array<string, string|int>}>
     */
    public function check(array $lines): array
    {
        $issues = [];
        $invoiceIds = [];
        $endToEndIds = [];

        foreach ($lines as $index => $line) {
            $invoiceId = (string) ($line['invoice_id'] ?? '');
            $endToEndId = (string) ($line['end_to_end_id'] ?? '');

            if ($invoiceId !== '') {
                if (isset($invoiceIds[$invoiceId])) {
                    $issues[] = ['path' => "lines.{$index}.invoice_id", 'code' => 'duplicate_invoice_in_file', 'params' => ['line' => $invoiceIds[$invoiceId] + 1]];
                }

                $invoiceIds[$invoiceId] ??= $index;
            }

            if ($endToEndId !== '') {
                if (isset($endToEndIds[$endToEndId])) {
                    $issues[] = ['path' => "lines.{$index}.end_to_end_id", 'code' => 'duplicate_end_to_end_in_file', 'params' => ['line' => $endToEndIds[$endToEndId] + 1]];
                }

                $endToEndIds[$endToEndId] ??= $index;
            }
        }

        $heldInvoices = $invoiceIds === [] ? [] : $this->held('invoice_id', array_keys($invoiceIds));
        $heldEndToEnd = $endToEndIds === [] ? [] : $this->held('end_to_end_id', array_keys($endToEndIds));

        foreach ($invoiceIds as $invoiceId => $index) {
            if (isset($heldInvoices[$invoiceId])) {
                $issues[] = ['path' => "lines.{$index}.invoice_id", 'code' => 'invoice_already_in_batch', 'params' => ['batch' => $heldInvoices[$invoiceId]]];
            }
        }

        foreach ($endToEndIds as $endToEndId => $index) {
            if (isset($heldEndToEnd[$endToEndId])) {
                $issues[] = ['path' => "lines.{$index}.end_to_end_id", 'code' => 'end_to_end_already_used', 'params' => ['batch' => $heldEndToEnd[$endToEndId]]];
            }
        }

        return $issues;
    }

    /**
     * Value of the column => reference of the batch that holds it.
     *
     * @param  list<int|string>  $values
     * @return array<string, string>
     */
    private function held(string $column, array $values): array
    {
        $lines = PaymentLine::query()
            ->whereIn($column, array_map('strval', $values))
            ->whereIn('status', self::HOLDING_STATUSES)
            ->whereHas('paymentBatch', static fn ($batch) => $batch->whereNotIn('status', ['rejected', 'cancelled']))
            ->get(['id', 'payment_batch_id', $column]);
        $references = PaymentBatch::query()->whereIn('id', $lines->pluck('payment_batch_id')->unique()->all())->pluck('batch_reference', 'id');
        $held = [];

        foreach ($lines as $line) {
            $held[(string) $line->getAttribute($column)] = (string) ($references[$line->payment_batch_id] ?? '');
        }

        return $held;
    }
}
