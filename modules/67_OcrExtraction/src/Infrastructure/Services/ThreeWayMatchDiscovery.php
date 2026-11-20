<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\OcrExtraction\Domain\Services\ThreeWayMatchEvaluator;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;

/**
 * Finds the open purchase orders (Mod 046) and the goods receipts (Mod 047) an extracted invoice belongs to and measures
 * the variances (Mod 048). An order is a candidate when the document names it, or when its total equals the invoice total.
 */
final readonly class ThreeWayMatchDiscovery
{
    /**
     * Orders that can still be invoiced.
     *
     * @var list<string>
     */
    private const array OpenStatuses = ['approved', 'sent', 'partially_received', 'received'];

    public function __construct(private ThreeWayMatchEvaluator $evaluator = new ThreeWayMatchEvaluator) {}

    /**
     * @return array{purchase_order_ids: list<string>, goods_receipt_ids: list<string>, evaluations: list<array<string, mixed>>}
     */
    public function discover(string $tenantId, string $vendorId, ExtractedPayloadDto $payload): array
    {
        $orders = DB::table('purchase_orders')
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $vendorId)
            ->whereNull('deleted_at')
            ->whereIn('status', self::OpenStatuses)
            ->get();

        $references = array_values(array_filter(array_map(fn (string $reference): string => $this->token($reference), $payload->orderReferences), static fn (string $token): bool => $token !== ''));
        $gross = $payload->totals['gross'] ?? null;
        $net = $payload->totals['net'] ?? null;

        $orderIds = [];
        $evaluations = [];

        foreach ($orders as $order) {
            $named = in_array($this->token((string) ($order->po_number ?? '')), $references, true) || in_array($this->token((string) $order->order_number), $references, true);
            $sameTotal = ($gross !== null && abs((float) $order->total_gross - $gross) <= max(1.0, $gross * 0.01))
                || ($net !== null && abs((float) $order->total_net - $net) <= max(1.0, $net * 0.01));

            if (! $named && ! $sameTotal) {
                continue;
            }

            $orderIds[] = (string) $order->id;
            $evaluations[] = [...$this->evaluate($tenantId, (string) $order->id, $payload->lines), 'matched_by' => $named ? 'reference' : 'amount'];
        }

        $receiptIds = $orderIds === [] ? [] : DB::table('purchase_receipts')
            ->where('tenant_id', $tenantId)
            ->whereIn('purchase_order_id', $orderIds)
            ->whereNull('deleted_at')
            ->orderBy('received_date')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return ['purchase_order_ids' => $orderIds, 'goods_receipt_ids' => $receiptIds, 'evaluations' => $evaluations];
    }

    /**
     * Compares document lines with one purchase order of the tenant: price and quantity variances per matched line, against
     * the order and against what was received.
     *
     * @param  list<array{description: string, quantity: float|null, unit_price: float|null}>  $lines
     * @return array<string, mixed>
     */
    public function evaluate(string $tenantId, string $orderId, array $lines): array
    {
        $order = DB::table('purchase_orders')->where('tenant_id', $tenantId)->where('id', $orderId)->whereNull('deleted_at')->first();
        [$percent, $amount] = $this->tolerance($tenantId);

        $orderLines = DB::table('purchase_order_lines')
            ->where('tenant_id', $tenantId)
            ->where('purchase_order_id', $orderId)
            ->whereNull('deleted_at')
            ->orderBy('line_number')
            ->get()
            ->map(static fn (object $line): array => [
                'id' => (string) $line->id,
                'description' => (string) $line->description,
                'quantity' => (float) $line->quantity_ordered,
                'unit_price' => (float) $line->unit_price,
                'quantity_received' => (float) $line->quantity_received,
            ])
            ->all();

        return [
            'purchase_order_id' => $orderId,
            'purchase_order_number' => (string) ($order->po_number ?? $order->order_number ?? ''),
            ...$this->evaluator->evaluate($lines, $orderLines, $percent, $amount),
        ];
    }

    /**
     * Price tolerance (percent, amount) of the tenant's procurement settings, else the module default.
     *
     * @return array{0: float, 1: float}
     */
    public function tolerance(string $tenantId): array
    {
        $settings = DB::table('procurement_settings')->where('tenant_id', $tenantId)->first();

        return [
            (float) ($settings->price_tolerance_percent ?? config('ocr_extraction.match_tolerance_percent')),
            (float) ($settings->price_tolerance_amount ?? config('ocr_extraction.match_tolerance_amount')),
        ];
    }

    private function token(string $reference): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '');
    }
}
