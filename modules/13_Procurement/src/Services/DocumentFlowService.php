<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Support\Decimal;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Procure-to-pay document flow: starting from any document of the chain it returns every linked upstream and downstream
 * document (requisition, RFQ, framework agreement, purchase order, goods receipt, supplier invoice with its 3-way match,
 * purchase return and credit note) as a graph, plus the ordered / received / invoiced / returned / remaining quantities.
 *
 * Invoices that settle several orders pull those orders (and their receipts) into the graph, so a consolidated invoice
 * shows its whole fulfilment. Every query is scoped to the tenant and ignores soft-deleted rows.
 */
final class DocumentFlowService
{
    public const array EntryTypes = ['requisition', 'rfq', 'purchase_order', 'goods_receipt', 'supplier_invoice', 'purchase_return'];

    /**
     * @return array{
     *     anchor: array{type: string, id: string},
     *     nodes: list<array{key: string, type: string, id: string, number: ?string, status: ?string, date: ?string, amount: ?string, currency: ?string, supplier_id: ?string, run_id: ?string, is_anchor: bool}>,
     *     edges: list<array{from: string, to: string, relation: string}>,
     *     quantities: array{ordered: string, received: string, invoiced: string, returned: string, remaining: string},
     *     orders: list<array{purchase_order_id: string, po_number: ?string, ordered: string, received: string, invoiced: string, returned: string, remaining: string}>
     * }
     */
    public function flow(string $tenantId, string $type, string $id): array
    {
        if (! in_array($type, self::EntryTypes, true)) {
            throw new ProcurementRuleViolation('Unsupported document type for the document flow.');
        }

        $ids = $this->resolve($tenantId, $type, $id);

        $requisitions = $this->rows('purchase_requisitions', $tenantId, $ids['requisitions']);
        $rfqs = $this->rows('procurement_rfqs', $tenantId, $ids['rfqs']);
        $orders = $this->rows('purchase_orders', $tenantId, $ids['orders']);
        $contracts = $this->rows('supplier_contracts', $tenantId, array_values(array_unique(array_filter($orders->pluck('supplier_contract_id')->all()))));
        $receipts = $ids['orders'] === [] ? collect() : DB::table('purchase_receipts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('purchase_order_id', $ids['orders'])->orderBy('received_date')->get();
        $returns = $ids['orders'] === [] ? collect() : DB::table('purchase_returns')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('purchase_order_id', $ids['orders'])->orderBy('return_date')->get();

        $creditNoteIds = array_values(array_filter($returns->pluck('credit_note_passive_invoice_id')->all()));
        $invoiceIds = array_values(array_unique([...$ids['invoices'], ...$creditNoteIds]));
        $invoices = $this->rows('passive_invoices', $tenantId, $invoiceIds);
        $allRuns = $invoiceIds === [] ? collect() : DB::table('invoice_matching_runs')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('passive_invoice_id', $invoiceIds)->get();
        $runs = $allRuns->where('is_current', true);

        $nodes = [];
        $edges = [];
        $node = static function (string $kind, object $row, ?string $number, ?string $status, ?string $date, ?string $amount, ?string $currency, ?string $supplierId) use (&$nodes, $type, $id): string {
            $key = $kind.':'.$row->id;
            $nodes[$key] = [
                'key' => $key,
                'type' => $kind,
                'id' => (string) $row->id,
                'number' => $number,
                'status' => $status,
                'date' => $date !== null ? substr($date, 0, 10) : null,
                'amount' => $amount,
                'currency' => $currency,
                'supplier_id' => $supplierId,
                'run_id' => null,
                'is_anchor' => $kind === $type && (string) $row->id === $id,
            ];

            return $key;
        };
        $edge = static function (string $from, string $to, string $relation) use (&$edges): void {
            $edges[$from.'>'.$to] = ['from' => $from, 'to' => $to, 'relation' => $relation];
        };

        foreach ($requisitions as $row) {
            $node('requisition', $row, $this->str($row->requisition_number ?? null), $this->str($row->status ?? null), $this->str($row->created_at ?? null), $this->amount($row->total_estimated_amount ?? null), $this->str($row->currency ?? null), null);
        }

        foreach ($rfqs as $row) {
            $node('rfq', $row, $this->str($row->rfq_number ?? null), $this->str($row->status ?? null), $this->str($row->issue_date ?? null), null, $this->str($row->currency ?? null), $this->str($row->awarded_supplier_id ?? null));

            if ($row->purchase_requisition_id !== null && $requisitions->contains('id', $row->purchase_requisition_id)) {
                $edge('requisition:'.$row->purchase_requisition_id, 'rfq:'.$row->id, 'sourced_by');
            }
        }

        foreach ($contracts as $row) {
            $node('framework_agreement', $row, $this->str($row->contract_number ?? null), $this->str($row->status ?? null), $this->str($row->starts_at ?? null), null, $this->str($row->currency ?? null), $this->str($row->supplier_id ?? null));
        }

        foreach ($orders as $row) {
            $poKey = $node('purchase_order', $row, $this->str($row->po_number ?? $row->order_number ?? null), $this->str($row->status ?? null), $this->str($row->date ?? null), $this->amount($row->total_amount ?? $row->total_gross ?? null), $this->str($row->currency ?? null), $this->str($row->supplier_id ?? null));

            if ($row->rfq_id !== null && $rfqs->contains('id', $row->rfq_id)) {
                $edge('rfq:'.$row->rfq_id, $poKey, 'awarded_to');
            } elseif ($row->purchase_requisition_id !== null && $requisitions->contains('id', $row->purchase_requisition_id)) {
                $edge('requisition:'.$row->purchase_requisition_id, $poKey, 'converted_to');
            }

            if ($row->supplier_contract_id !== null && $contracts->contains('id', $row->supplier_contract_id)) {
                $edge('framework_agreement:'.$row->supplier_contract_id, $poKey, 'called_off_by');
            }
        }

        foreach ($receipts as $row) {
            $receiptKey = $node('goods_receipt', $row, $this->str($row->receipt_number ?? null), $this->str($row->inspection_status ?? null), $this->str($row->received_date ?? null), null, null, null);
            $edge('purchase_order:'.$row->purchase_order_id, $receiptKey, 'received_by');
        }

        $matchByInvoice = $runs->keyBy('passive_invoice_id');

        foreach ($invoices as $row) {
            $run = $matchByInvoice->get($row->id);
            $invoiceKey = $node('supplier_invoice', $row, $this->str($row->invoice_number ?? null), $this->str($run->status ?? $row->match_status ?? null), $this->str($row->date ?? null), $this->amount($row->total_amount ?? null), $this->str($row->currency ?? null), $this->str($row->supplier_id ?? null));
            $nodes[$invoiceKey]['run_id'] = $run !== null ? (string) $run->id : null;

            $linkedOrders = $run !== null ? [(string) $run->purchase_order_id] : [];

            if ($row->purchase_order_id !== null) {
                $linkedOrders[] = (string) $row->purchase_order_id;
            }

            foreach ($allRuns->where('passive_invoice_id', $row->id)->pluck('purchase_order_id') as $orderId) {
                $linkedOrders[] = (string) $orderId;
            }

            foreach (array_unique($linkedOrders) as $orderId) {
                if (isset($nodes['purchase_order:'.$orderId])) {
                    $edge('purchase_order:'.$orderId, $invoiceKey, $run !== null ? 'matched_by' : 'invoiced_by');
                }
            }
        }

        foreach ($returns as $row) {
            $returnKey = $node('purchase_return', $row, $this->str($row->return_number ?? null), $this->str($row->status ?? null), $this->str($row->return_date ?? null), $this->amount($row->total_amount ?? null), null, $this->str($row->supplier_id ?? null));
            $edge($row->purchase_receipt_id !== null && isset($nodes['goods_receipt:'.$row->purchase_receipt_id]) ? 'goods_receipt:'.$row->purchase_receipt_id : 'purchase_order:'.$row->purchase_order_id, $returnKey, 'returned_by');

            if ($row->credit_note_passive_invoice_id !== null && isset($nodes['supplier_invoice:'.$row->credit_note_passive_invoice_id])) {
                $edge($returnKey, 'supplier_invoice:'.$row->credit_note_passive_invoice_id, 'credited_by');
            }
        }

        return [
            'anchor' => ['type' => $type, 'id' => $id],
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            ...$this->quantities($tenantId, $ids['orders'], $orders),
        ];
    }

    /**
     * Expand the entry document to the sets of related requisitions, RFQs, orders and invoices.
     *
     * @return array{requisitions: list<string>, rfqs: list<string>, orders: list<string>, invoices: list<string>}
     */
    private function resolve(string $tenantId, string $type, string $id): array
    {
        $requisitions = [];
        $rfqs = [];
        $orders = [];
        $invoices = [];

        $find = fn (string $table) => DB::table($table)->where('tenant_id', $tenantId)->whereNull('deleted_at');

        switch ($type) {
            case 'requisition':
                $exists = $find('purchase_requisitions')->where('id', $id)->exists();
                $requisitions = $exists ? [$id] : [];

                break;
            case 'rfq':
                $exists = $find('procurement_rfqs')->where('id', $id)->exists();
                $rfqs = $exists ? [$id] : [];

                break;
            case 'purchase_order':
                $exists = $find('purchase_orders')->where('id', $id)->exists();
                $orders = $exists ? [$id] : [];

                break;
            case 'goods_receipt':
                $orderId = $find('purchase_receipts')->where('id', $id)->value('purchase_order_id');
                $orders = $orderId !== null ? [(string) $orderId] : [];

                break;
            case 'purchase_return':
                $orderId = $find('purchase_returns')->where('id', $id)->value('purchase_order_id');
                $orders = $orderId !== null ? [(string) $orderId] : [];

                break;
            default:
                $invoice = $find('passive_invoices')->where('id', $id)->first();

                if ($invoice !== null) {
                    $invoices = [$id];

                    if ($invoice->purchase_order_id !== null) {
                        $orders[] = (string) $invoice->purchase_order_id;
                    }

                    $orders = [...$orders, ...$this->strings($find('invoice_matching_runs')->where('passive_invoice_id', $id)->pluck('purchase_order_id')->all())];

                    // A credit note points back at the return that requested it.
                    $returnOrders = $find('purchase_returns')->where('credit_note_passive_invoice_id', $id)->pluck('purchase_order_id')->all();
                    $orders = [...$orders, ...$this->strings($returnOrders)];
                }
        }

        if ($requisitions === [] && $rfqs === [] && $orders === []) {
            throw new NotFoundHttpException('Document not found for the document flow.');
        }

        // Upstream and downstream: requisitions <-> RFQs <-> orders.
        for ($pass = 0; $pass < 2; $pass++) {
            if ($orders !== []) {
                $po = $find('purchase_orders')->whereIn('id', $orders)->get(['purchase_requisition_id', 'rfq_id']);
                $requisitions = [...$requisitions, ...$this->strings($po->pluck('purchase_requisition_id')->all())];
                $rfqs = [...$rfqs, ...$this->strings($po->pluck('rfq_id')->all())];
            }

            if ($rfqs !== []) {
                $rfqRows = $find('procurement_rfqs')->whereIn('id', $rfqs)->get(['purchase_requisition_id', 'purchase_order_id']);
                $requisitions = [...$requisitions, ...$this->strings($rfqRows->pluck('purchase_requisition_id')->all())];
                $orders = [...$orders, ...$this->strings($rfqRows->pluck('purchase_order_id')->all())];
            }

            if ($requisitions !== []) {
                $rfqs = [...$rfqs, ...$this->strings($find('procurement_rfqs')->whereIn('purchase_requisition_id', array_unique($requisitions))->pluck('id')->all())];
                $orders = [...$orders, ...$this->strings($find('purchase_orders')->whereIn('purchase_requisition_id', array_unique($requisitions))->pluck('id')->all())];
            }

            if ($rfqs !== []) {
                $orders = [...$orders, ...$this->strings($find('purchase_orders')->whereIn('rfq_id', array_unique($rfqs))->pluck('id')->all())];
            }
        }

        $orders = array_values(array_unique($orders));

        if ($orders !== []) {
            // Invoices of the orders, and the other orders settled by those same invoices (multi-order consolidation).
            $runs = $find('invoice_matching_runs')->whereIn('purchase_order_id', $orders)->pluck('passive_invoice_id')->all();
            $direct = $find('passive_invoices')->whereIn('purchase_order_id', $orders)->pluck('id')->all();
            $invoices = array_values(array_unique([...$invoices, ...$this->strings($runs), ...$this->strings($direct)]));

            if ($invoices !== []) {
                $orders = array_values(array_unique([...$orders, ...$this->strings($find('invoice_matching_runs')->whereIn('passive_invoice_id', $invoices)->pluck('purchase_order_id')->all())]));
            }
        }

        return [
            'requisitions' => array_values(array_unique($requisitions)),
            'rfqs' => array_values(array_unique($rfqs)),
            'orders' => $orders,
            'invoices' => $invoices,
        ];
    }

    /**
     * Ordered, received, invoiced, returned and remaining quantities of the orders, in total and per order.
     *
     * @param  list<string>  $orderIds
     * @param  Collection<int, stdClass>  $orders
     * @return array{quantities: array{ordered: string, received: string, invoiced: string, returned: string, remaining: string}, orders: list<array{purchase_order_id: string, po_number: ?string, ordered: string, received: string, invoiced: string, returned: string, remaining: string}>}
     */
    private function quantities(string $tenantId, array $orderIds, Collection $orders): array
    {
        $total = ['ordered' => '0', 'received' => '0', 'invoiced' => '0', 'returned' => '0', 'remaining' => '0'];
        $perOrder = [];

        if ($orderIds !== []) {
            $lines = DB::table('purchase_order_lines')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('purchase_order_id', $orderIds)->get();
            $lineIds = $lines->pluck('id')->all();

            // The document rows are the source of truth; the counters kept on the order line cover legacy data.
            $receiptTotals = DB::table('purchase_receipt_lines')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('purchase_order_line_id', $lineIds)
                ->groupBy('purchase_order_line_id')
                ->selectRaw('purchase_order_line_id, SUM(quantity_received) as received, SUM(quantity_returned) as returned')
                ->get()->keyBy('purchase_order_line_id');
            $invoicedTotals = DB::table('invoice_match_lines as ml')
                ->join('invoice_matching_runs as r', 'r.id', '=', 'ml.run_id')
                ->where('ml.tenant_id', $tenantId)->whereNull('ml.deleted_at')->whereNull('r.deleted_at')->where('r.is_current', true)
                ->whereIn('ml.purchase_order_line_id', $lineIds)
                ->groupBy('ml.purchase_order_line_id')
                ->selectRaw('ml.purchase_order_line_id as line_id, SUM(ml.invoiced_quantity) as invoiced')
                ->get()->keyBy('line_id');

            foreach ($orders as $order) {
                $row = ['ordered' => '0', 'received' => '0', 'invoiced' => '0', 'returned' => '0', 'remaining' => '0'];

                foreach ($lines->where('purchase_order_id', $order->id) as $line) {
                    $ordered = Decimal::sub((string) $line->quantity_ordered, (string) ($line->quantity_cancelled ?? '0'), 4);
                    $received = Decimal::max((string) $line->quantity_received, (string) ($receiptTotals->get($line->id)->received ?? '0'), 4);
                    $invoiced = Decimal::max((string) $line->quantity_invoiced, (string) ($invoicedTotals->get($line->id)->invoiced ?? '0'), 4);
                    $returned = Decimal::max((string) ($line->quantity_returned ?? '0'), (string) ($receiptTotals->get($line->id)->returned ?? '0'), 4);
                    $row['ordered'] = Decimal::add($row['ordered'], $ordered, 4);
                    $row['received'] = Decimal::add($row['received'], $received, 4);
                    $row['invoiced'] = Decimal::add($row['invoiced'], $invoiced, 4);
                    $row['returned'] = Decimal::add($row['returned'], $returned, 4);
                    $row['remaining'] = Decimal::add($row['remaining'], Decimal::max('0', Decimal::sub($ordered, $received, 4), 4), 4);
                }

                foreach ($row as $field => $value) {
                    $total[$field] = Decimal::add($total[$field], $value, 4);
                }

                $perOrder[] = ['purchase_order_id' => (string) $order->id, 'po_number' => $this->str($order->po_number ?? $order->order_number ?? null), ...$row];
            }
        }

        return ['quantities' => $total, 'orders' => $perOrder];
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, stdClass>
     */
    private function rows(string $table, string $tenantId, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DB::table($table)->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('id', $ids)->get();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_map(static fn (mixed $value): string => (string) $value, array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== '')));
    }

    private function str(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function amount(mixed $value): ?string
    {
        return $value === null ? null : Decimal::of((string) $value, 2);
    }
}
