<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\SupplierPriceList;
use SimpleXMLElement;

/**
 * Compares what a supplier invoiced, line by line, with the order and with the supplier's price list on that date. The
 * lines come from the caller or, for an electronic invoice, from its FatturaPA XML. A line is matched by part number,
 * then by description. The tolerance is the caller's: nothing here assumes one.
 */
final class PurchasePriceCheckService
{
    /**
     * @param  list<array{code?: ?string, description?: ?string, quantity?: mixed, unit_price: mixed}>|null  $lines
     * @return array{purchase_order_id: string, tolerance_percent: string, has_differences: bool, lines: list<array<string, mixed>>}
     */
    public function check(PurchaseOrder $order, ?array $lines, ?PassiveInvoice $invoice, string $tolerancePercent, ?string $date = null): array
    {
        $invoiceLines = $lines ?? $this->linesOf($invoice);
        $on = $date ?? $invoice?->date?->toDateString() ?? CarbonImmutable::today()->toDateString();
        $orderLines = $order->lines()->with('catalog')->get();
        $results = array_map(fn (array $line): array => $this->compare($line, $orderLines, $tolerancePercent, $on), $invoiceLines);

        return [
            'purchase_order_id' => (string) $order->id,
            'tolerance_percent' => $tolerancePercent,
            'has_differences' => collect($results)->contains(static fn (array $result): bool => $result['status'] !== 'ok'),
            'lines' => $results,
        ];
    }

    /**
     * @param  array{code?: ?string, description?: ?string, quantity?: mixed, unit_price: mixed}  $line
     * @param  Collection<int, PurchaseOrderLine>  $orderLines
     * @return array<string, mixed>
     */
    private function compare(array $line, Collection $orderLines, string $tolerance, string $on): array
    {
        $match = $this->match($line, $orderLines);
        $invoiced = bcadd((string) $line['unit_price'], '0', 4);
        $result = [
            'code' => $line['code'] ?? null,
            'description' => $line['description'] ?? null,
            'quantity' => isset($line['quantity']) ? bcadd((string) $line['quantity'], '0', 4) : null,
            'invoiced_unit_price' => $invoiced,
            'purchase_order_line_id' => $match?->id,
            'ordered_unit_price' => null,
            'list_unit_price' => null,
            'variance_amount' => null,
            'variance_percent' => null,
            'status' => 'unmatched',
        ];

        if ($match === null) {
            return $result;
        }

        $ordered = bcadd((string) $match->unit_price, '0', 4);
        $listPrice = $this->listPrice($match, (string) ($line['quantity'] ?? $match->quantity_ordered), $on);
        $variance = Decimal::sub($invoiced, $ordered);
        $percent = Decimal::isPositive($ordered) ? Decimal::div(Decimal::mul($variance, '100'), $ordered) : '0';

        return [
            'purchase_order_line_id' => $match->id,
            'ordered_unit_price' => $ordered,
            'list_unit_price' => $listPrice,
            'variance_amount' => bcadd($variance, '0', 4),
            'variance_percent' => Decimal::round2($percent),
            'status' => $this->statusOf($invoiced, $ordered, $listPrice, $percent, $tolerance),
        ] + $result;
    }

    private function statusOf(string $invoiced, string $ordered, ?string $listPrice, string $percent, string $tolerance): string
    {
        if (Decimal::compare(Decimal::abs($percent), $tolerance) > 0) {
            return Decimal::compare($invoiced, $ordered) > 0 ? 'above_order' : 'below_order';
        }

        return $listPrice !== null && Decimal::compare($invoiced, $listPrice) > 0 && Decimal::compare(Decimal::div(Decimal::mul(Decimal::sub($invoiced, $listPrice), '100'), $listPrice), $tolerance) > 0
            ? 'above_list'
            : 'ok';
    }

    /**
     * @param  array{code?: ?string, description?: ?string}  $line
     * @param  Collection<int, PurchaseOrderLine>  $orderLines
     */
    private function match(array $line, Collection $orderLines): ?PurchaseOrderLine
    {
        $code = trim((string) ($line['code'] ?? ''));
        $description = mb_strtolower(trim((string) ($line['description'] ?? '')));

        return $orderLines->first(static fn (PurchaseOrderLine $orderLine): bool => $code !== '' && in_array($code, [$orderLine->catalog?->supplier_part_number, $orderLine->catalog?->internal_sku], true))
            ?? $orderLines->first(static fn (PurchaseOrderLine $orderLine): bool => $description !== '' && mb_strtolower(trim((string) $orderLine->description)) === $description);
    }

    private function listPrice(PurchaseOrderLine $line, string $quantity, string $on): ?string
    {
        if ($line->supplier_catalog_id === null) {
            return null;
        }

        $price = SupplierPriceList::query()
            ->where('supplier_catalog_id', $line->supplier_catalog_id)
            ->where('min_quantity', '<=', $quantity)
            ->whereDate('valid_from', '<=', $on)
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $on))
            ->orderByDesc('min_quantity')
            ->first();

        return $price === null ? null : bcadd((string) $price->unit_price, '0', 4);
    }

    /**
     * @return list<array{code: ?string, description: ?string, quantity: ?string, unit_price: string}>
     */
    private function linesOf(?PassiveInvoice $invoice): array
    {
        $xml = (string) $invoice?->xml_payload;

        if ($xml === '') {
            throw new ProcurementRuleViolation('Give the invoice lines: this supplier invoice has no XML to read them from.');
        }

        libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if ($document === false) {
            throw new ProcurementRuleViolation('The XML of this supplier invoice cannot be read.');
        }

        $lines = [];

        foreach ($document->xpath('//*[local-name()="DettaglioLinee"]') ?: [] as $line) {
            $quantity = trim((string) $line->Quantita);

            $lines[] = [
                'code' => trim((string) ($line->CodiceArticolo->CodiceValore ?? '')) ?: null,
                'description' => trim((string) $line->Descrizione) ?: null,
                'quantity' => $quantity === '' ? null : $quantity,
                'unit_price' => trim((string) $line->PrezzoUnitario),
            ];
        }

        if ($lines === []) {
            throw new ProcurementRuleViolation('This supplier invoice has no lines in its XML.');
        }

        return $lines;
    }
}
