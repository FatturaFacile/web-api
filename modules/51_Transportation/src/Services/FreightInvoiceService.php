<?php

declare(strict_types=1);

namespace Modules\Transportation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Transportation\Models\TmsCarrier;
use Modules\Transportation\Models\TmsFreightInvoice;
use Modules\Transportation\Models\TmsFreightInvoiceLine;
use Modules\Transportation\Models\TmsTransportOrder;
use RoundingMode;

/**
 * Freight invoice audit. Every line is compared with the expected rate of its transport order: the expected cost kept on the
 * order, else the rate recalculated from the tariff tables of the carrier. The variance is billed minus expected, in amount
 * and in percent of the expected; inside the tolerance the line is matched, above it over billed, below it under billed, and a
 * line without an order or without a rate is unmatched. A contested invoice goes through a dispute until it is resolved.
 */
final class FreightInvoiceService
{
    public const string DefaultTolerance = '2';

    /** Dispute statuses that close an invoice for changes. */
    private const array Resolved = [TmsFreightInvoice::ResolvedAccepted, TmsFreightInvoice::ResolvedCredited, TmsFreightInvoice::Rejected];

    /** @var list<string> */
    private const array With = ['carrier:id,code,name', 'lines.transportOrder:id,number'];

    public function __construct(private readonly RateCalculator $rates) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): TmsFreightInvoice
    {
        return DB::transaction(function () use ($tenantId, $data): TmsFreightInvoice {
            $carrier = TmsCarrier::query()->where('tenant_id', $tenantId)->findOrFail($data['carrier_id']);
            $lines = $this->normalizeLines($tenantId, $carrier, (array) $data['lines']);

            $invoice = TmsFreightInvoice::create([
                ...array_diff_key($data, ['lines' => true]),
                'tenant_id' => $tenantId,
                'currency' => Str::upper((string) ($data['currency'] ?? $carrier->currency)),
                'total_amount' => $this->total($lines),
            ]);
            $this->writeLines($invoice, $lines);

            return $this->present($invoice);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TmsFreightInvoice $invoice, array $data): TmsFreightInvoice
    {
        return DB::transaction(function () use ($invoice, $data): TmsFreightInvoice {
            $locked = $this->lock($invoice);
            $this->assertNoDispute($locked);
            $changes = array_diff_key($data, ['lines' => true, 'carrier_id' => true]);

            if (isset($changes['currency'])) {
                $changes['currency'] = Str::upper((string) $changes['currency']);
            }

            if (array_key_exists('lines', $data)) {
                $carrier = TmsCarrier::query()->where('tenant_id', $locked->tenant_id)->findOrFail($locked->carrier_id);
                $lines = $this->normalizeLines((string) $locked->tenant_id, $carrier, (array) $data['lines']);
                $locked->lines()->delete();
                $this->writeLines($locked, $lines);
                $changes['total_amount'] = $this->total($lines);
            }

            // Any change of the invoice sends it back to the audit.
            $locked->update([...$changes, ...$this->reset()]);

            if (! array_key_exists('lines', $data)) {
                $locked->lines()->update(['expected_amount' => null, 'variance_amount' => null, 'variance_percent' => null, 'audit_status' => TmsFreightInvoiceLine::Pending]);
            }

            return $this->present($locked);
        });
    }

    public function delete(TmsFreightInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $locked = $this->lock($invoice);

            if ($locked->dispute_status !== TmsFreightInvoice::None) {
                throw ValidationException::withMessages(['dispute_status' => [__('transportation_errors.invoice.disputed')]]);
            }

            $locked->lines()->delete();
            $locked->delete();
        });
    }

    public function audit(TmsFreightInvoice $invoice, ?string $tolerancePercent): TmsFreightInvoice
    {
        return DB::transaction(function () use ($invoice, $tolerancePercent): TmsFreightInvoice {
            $locked = $this->lock($invoice);

            if (in_array($locked->dispute_status, self::Resolved, true)) {
                throw ValidationException::withMessages(['dispute_status' => [__('transportation_errors.invoice.resolved')]]);
            }

            $tolerance = $tolerancePercent ?? self::DefaultTolerance;
            $carrier = TmsCarrier::query()->where('tenant_id', $locked->tenant_id)->findOrFail($locked->carrier_id);
            $lines = $locked->lines()->with('transportOrder')->get();

            $expectedTotal = '0';
            $varianceTotal = '0';
            $statuses = [];
            $billedPerOrder = [];

            foreach ($lines as $line) {
                $order = $line->transportOrder;
                $expected = $order === null ? null : $this->expectedAmount($carrier, $order, $locked);

                if ($expected === null) {
                    $line->update(['expected_amount' => null, 'variance_amount' => null, 'variance_percent' => null, 'audit_status' => TmsFreightInvoiceLine::Unmatched]);
                    $statuses[] = TmsFreightInvoiceLine::Unmatched;
                } else {
                    $variance = bcsub((string) $line->billed_amount, $expected, 2);
                    $percent = bccomp($expected, '0', 2) === 0
                        ? (bccomp((string) $line->billed_amount, '0', 2) === 0 ? '0.00' : '100.00')
                        : bcround(bcdiv(bcmul($variance, '100', 8), $expected, 8), 2, RoundingMode::HalfAwayFromZero);
                    $status = match (true) {
                        bccomp($this->abs($percent), $tolerance, 2) <= 0 => TmsFreightInvoiceLine::Matched,
                        bccomp($variance, '0', 2) > 0 => TmsFreightInvoiceLine::OverBilled,
                        default => TmsFreightInvoiceLine::UnderBilled,
                    };

                    $line->update(['expected_amount' => $expected, 'variance_amount' => $variance, 'variance_percent' => $percent, 'audit_status' => $status]);
                    $statuses[] = $status;
                    $expectedTotal = bcadd($expectedTotal, $expected, 2);
                    $varianceTotal = bcadd($varianceTotal, $variance, 2);
                }

                if ($order !== null) {
                    $billedPerOrder[$order->id] = bcadd($billedPerOrder[$order->id] ?? '0', (string) $line->billed_amount, 2);
                }
            }

            foreach ($billedPerOrder as $orderId => $billed) {
                TmsTransportOrder::query()->whereKey($orderId)->update(['actual_cost' => $billed]);
            }

            $locked->update([
                'tolerance_percent' => $tolerance,
                'expected_total' => $expectedTotal,
                'variance_amount' => $varianceTotal,
                'audit_status' => match (true) {
                    in_array(TmsFreightInvoiceLine::OverBilled, $statuses, true) => TmsFreightInvoice::OverBilled,
                    in_array(TmsFreightInvoiceLine::UnderBilled, $statuses, true) => TmsFreightInvoice::UnderBilled,
                    in_array(TmsFreightInvoiceLine::Unmatched, $statuses, true) => TmsFreightInvoice::Unmatched,
                    default => TmsFreightInvoice::Matched,
                },
                'audited_at' => now(),
            ]);

            return $this->present($locked);
        });
    }

    public function dispute(TmsFreightInvoice $invoice, string $reason): TmsFreightInvoice
    {
        return DB::transaction(function () use ($invoice, $reason): TmsFreightInvoice {
            $locked = $this->lock($invoice);

            if ($locked->audit_status === TmsFreightInvoice::Pending || $locked->audit_status === TmsFreightInvoice::Matched) {
                throw ValidationException::withMessages(['audit_status' => [__('transportation_errors.invoice.nothing_to_dispute')]]);
            }

            if ($locked->dispute_status !== TmsFreightInvoice::None) {
                throw ValidationException::withMessages(['dispute_status' => [__('transportation_errors.invoice.dispute_exists', ['status' => $locked->dispute_status])]]);
            }

            $locked->update(['dispute_status' => TmsFreightInvoice::Open, 'dispute_reason' => $reason]);

            return $this->present($locked);
        });
    }

    public function resolveDispute(TmsFreightInvoice $invoice, string $resolution, string $note, ?string $creditedAmount): TmsFreightInvoice
    {
        return DB::transaction(function () use ($invoice, $resolution, $note, $creditedAmount): TmsFreightInvoice {
            $locked = $this->lock($invoice);

            if ($locked->dispute_status !== TmsFreightInvoice::Open) {
                throw ValidationException::withMessages(['dispute_status' => [__('transportation_errors.invoice.no_open_dispute')]]);
            }

            if ($resolution === 'credited') {
                $limit = $locked->audit_status === TmsFreightInvoice::OverBilled ? (string) $locked->variance_amount : (string) $locked->total_amount;

                if ($creditedAmount === null || bccomp($creditedAmount, $limit, 2) > 0) {
                    throw ValidationException::withMessages(['credited_amount' => [__('transportation_errors.invoice.credit_range', ['limit' => $limit])]]);
                }
            } elseif ($creditedAmount !== null) {
                throw ValidationException::withMessages(['credited_amount' => [__('transportation_errors.invoice.credit_only_when_credited')]]);
            }

            $locked->update([
                'dispute_status' => match ($resolution) {
                    'accepted' => TmsFreightInvoice::ResolvedAccepted,
                    'credited' => TmsFreightInvoice::ResolvedCredited,
                    default => TmsFreightInvoice::Rejected,
                },
                'resolution_note' => $note,
                'credited_amount' => $creditedAmount,
            ]);

            return $this->present($locked);
        });
    }

    /**
     * The expected cost kept on the order, else the rate recalculated for the carrier of the invoice.
     */
    private function expectedAmount(TmsCarrier $carrier, TmsTransportOrder $order, TmsFreightInvoice $invoice): ?string
    {
        if ($order->expected_cost !== null) {
            return (string) $order->expected_cost;
        }

        $date = $order->delivered_at ?? $order->pickup_from ?? $invoice->invoice_date;

        return $this->rates->rate($carrier, (string) $order->weight_kg, $order->distance_km !== null ? (string) $order->distance_km : null, $order->origin_zone, $order->destination_zone, $date, (string) $invoice->currency)['total_amount'] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array{transport_order_id: string|null, description: string|null, billed_amount: string}>
     */
    private function normalizeLines(string $tenantId, TmsCarrier $carrier, array $lines): array
    {
        $normalized = [];

        foreach ($lines as $index => $line) {
            $orderId = $line['transport_order_id'] ?? null;

            if ($orderId !== null) {
                $order = TmsTransportOrder::query()->where('tenant_id', $tenantId)->find($orderId);

                if ($order === null || $order->carrier_id !== $carrier->id) {
                    throw ValidationException::withMessages(["lines.$index.transport_order_id" => [__('transportation_errors.invoice.order_other_carrier')]]);
                }
            }

            $normalized[] = ['transport_order_id' => $orderId, 'description' => $line['description'] ?? null, 'billed_amount' => bcadd((string) $line['billed_amount'], '0', 2)];
        }

        return $normalized;
    }

    /**
     * @param  list<array{transport_order_id: string|null, description: string|null, billed_amount: string}>  $lines
     */
    private function writeLines(TmsFreightInvoice $invoice, array $lines): void
    {
        foreach ($lines as $line) {
            TmsFreightInvoiceLine::create([...$line, 'tenant_id' => $invoice->tenant_id, 'freight_invoice_id' => $invoice->id]);
        }
    }

    /**
     * @param  list<array{billed_amount: string}>  $lines
     */
    private function total(array $lines): string
    {
        return array_reduce($lines, static fn (string $sum, array $line): string => bcadd($sum, $line['billed_amount'], 2), '0.00');
    }

    /**
     * @return array<string, mixed>
     */
    private function reset(): array
    {
        return ['audit_status' => TmsFreightInvoice::Pending, 'expected_total' => '0', 'variance_amount' => '0', 'audited_at' => null];
    }

    private function assertNoDispute(TmsFreightInvoice $invoice): void
    {
        if ($invoice->dispute_status !== TmsFreightInvoice::None) {
            throw ValidationException::withMessages(['dispute_status' => [__('transportation_errors.invoice.disputed')]]);
        }
    }

    private function abs(string $value): string
    {
        return ltrim($value, '-');
    }

    private function present(TmsFreightInvoice $invoice): TmsFreightInvoice
    {
        return $invoice->refresh()->load(self::With);
    }

    private function lock(TmsFreightInvoice $invoice): TmsFreightInvoice
    {
        return TmsFreightInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
    }
}
