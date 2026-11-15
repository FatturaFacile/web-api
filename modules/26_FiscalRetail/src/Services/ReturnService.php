<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Services;

use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosSession;
use App\Services\Inventory\StockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\FiscalRetail\Actions\IssueCommercialDocument;
use Modules\FiscalRetail\Models\CommercialDocument;
use Modules\FiscalRetail\Models\CommercialDocumentLine;
use Modules\FiscalRetail\Models\RetailReturn;
use Modules\FiscalRetail\Models\RetailReturnLine;

/**
 * Returns and exchanges of the goods of a completed POS sale, in whole or in part, settled in an open cash session.
 *
 * The refund of a line is its share of the sale: when the ticket had promotion discounts, each returned line gives
 * back its net less the same percentage of discount the ticket had at its VAT rate. Stock goes back, the commercial
 * document of the sale (if any) gets a return document, and the cash given back lowers the expected cash of the
 * drawer. An exchange sells new goods in the same session and uses the value of the returned ones as a payment of the
 * new ticket; only the rest is paid by the customer or refunded.
 */
final class ReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly IssueCommercialDocument $documents,
        private readonly TicketService $tickets,
        private readonly RetailNumbers $numbers,
    ) {}

    /**
     * @param  array{lines: list<array{pos_sale_item_id: string, quantity: numeric-string|float|int}>, refund_method: string, reason?: string|null, exchange?: array<string, mixed>|null}  $data
     */
    public function process(PosSale $sale, PosSession $session, array $data, ?string $userId): RetailReturn
    {
        return DB::transaction(function () use ($sale, $session, $data, $userId): RetailReturn {
            $sale = PosSale::query()->lockForUpdate()->findOrFail($sale->id);
            $session = PosSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['pos_sale_id' => __('pos.return_sale_not_returnable')]);
            }

            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['pos_session_id' => __('pos.session_closed')]);
            }

            $items = PosSaleItem::query()->where('pos_sale_id', $sale->id)->get();
            $lines = $this->refundLines($items, $data['lines']);

            $totalNet = $this->total($lines, 'net_amount');
            $totalTax = $this->total($lines, 'tax_amount');
            $totalGross = bcadd($totalNet, $totalTax, 2);

            $credit = '0.00';
            $exchangeSale = null;

            if (! empty($data['exchange'])) {
                $quote = $this->tickets->quote($data['exchange']['items']);
                $credit = bccomp($totalGross, $quote['total_gross'], 2) > 0 ? $quote['total_gross'] : $totalGross;
                $exchangeSale = $this->tickets->create($session, $data['exchange'], $credit);
            }

            $payout = bcsub($totalGross, $credit, 2);
            $isCash = $data['refund_method'] === 'cash';

            $return = RetailReturn::create([
                'tenant_id' => $sale->tenant_id,
                'pos_sale_id' => $sale->id,
                'pos_session_id' => $session->id,
                'exchange_sale_id' => $exchangeSale?->id,
                'number' => $this->numbers->next('retail_returns', 'RET', (string) $sale->tenant_id),
                'reason' => $data['reason'] ?? null,
                'refund_method' => $data['refund_method'],
                'total_net' => $totalNet,
                'total_tax' => $totalTax,
                'total_gross' => $totalGross,
                'credit_applied' => $credit,
                'cash_refunded' => $isCash ? $payout : '0.00',
                'other_refunded' => $isCash ? '0.00' : $payout,
                'user_id' => $userId,
            ]);

            $models = [];

            foreach ($lines as $line) {
                $models[] = RetailReturnLine::create(['tenant_id' => $sale->tenant_id, 'retail_return_id' => $return->id] + $line);
            }

            $this->stock->recordForLines('in', $sale, $models);

            $document = $this->returnDocument($sale, $items, $lines);

            if ($document !== null) {
                $return->update(['commercial_document_id' => $document->id]);
            }

            return $return->load('lines');
        });
    }

    /**
     * Quantity of each sale line already given back.
     *
     * @return array<string, string>
     */
    public function returnedQuantities(PosSale $sale): array
    {
        return RetailReturnLine::query()
            ->join('retail_returns as r', 'r.id', '=', 'retail_return_lines.retail_return_id')
            ->where('r.pos_sale_id', $sale->id)
            ->selectRaw('retail_return_lines.pos_sale_item_id as item_id, sum(retail_return_lines.quantity) as returned')
            ->groupBy('retail_return_lines.pos_sale_item_id')
            ->pluck('returned', 'item_id')
            ->map(static fn (mixed $value): string => bcadd((string) $value, '0', 4))
            ->all();
    }

    /**
     * @param  Collection<int, PosSaleItem>  $items
     * @param  list<array{pos_sale_item_id: string, quantity: numeric-string|float|int}>  $requested
     * @return list<array<string, mixed>>
     */
    private function refundLines($items, array $requested): array
    {
        $sale = $items->first();
        $returned = $sale === null ? [] : $this->returnedQuantities(PosSale::query()->findOrFail($sale->pos_sale_id));
        $positive = $items->filter(static fn (PosSaleItem $item): bool => bccomp((string) $item->unit_price, '0', 4) > 0);
        $discounts = $items->filter(static fn (PosSaleItem $item): bool => bccomp((string) $item->unit_price, '0', 4) < 0);
        $lines = [];

        foreach ($requested as $position => $request) {
            $item = $positive->firstWhere('id', $request['pos_sale_item_id']);
            $quantity = bcadd((string) $request['quantity'], '0', 4);

            if ($item === null) {
                throw ValidationException::withMessages(["lines.{$position}.pos_sale_item_id" => __('pos.return_item_unknown')]);
            }

            $open = bcsub((string) $item->quantity, $returned[$item->id] ?? '0', 4);

            if (bccomp($quantity, '0', 4) <= 0 || bccomp($quantity, $open, 4) > 0) {
                throw ValidationException::withMessages(["lines.{$position}.quantity" => __('pos.return_quantity_exceeds')]);
            }

            $rate = bcadd((string) $item->tax_rate, '0', 2);
            $positiveNet = $this->netAtRate($positive, $rate);
            $discountNet = bcmul($this->netAtRate($discounts, $rate), '-1', 2);
            $share = bcdiv($quantity, (string) $item->quantity, 8);
            $gross = bcmul((string) $item->line_total, $share, 6);
            $ratio = bccomp($positiveNet, '0', 2) > 0 ? bcdiv($discountNet, $positiveNet, 8) : '0';
            $net = bcround(bcmul($gross, bcsub('1', $ratio, 8), 6), 2);
            $tax = bcround(bcdiv(bcmul($net, $rate, 6), '100', 6), 2);

            $lines[] = [
                'pos_sale_item_id' => (string) $item->id,
                'product_id' => $item->product_id,
                'description' => (string) $item->description,
                'quantity' => $quantity,
                'tax_rate' => $rate,
                'net_amount' => $net,
                'tax_amount' => $tax,
                'gross_amount' => bcadd($net, $tax, 2),
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('pos.return_quantity_exceeds')]);
        }

        return $lines;
    }

    /**
     * @param  Collection<int, PosSaleItem>  $items
     */
    private function netAtRate($items, string $rate): string
    {
        return $items->filter(static fn (PosSaleItem $item): bool => bccomp((string) $item->tax_rate, $rate, 2) === 0)
            ->reduce(static fn (string $sum, PosSaleItem $item): string => bcadd($sum, (string) $item->line_total, 2), '0.00');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function total(array $lines, string $field): string
    {
        return array_reduce($lines, static fn (string $sum, array $line): string => bcadd($sum, (string) $line[$field], 2), '0.00');
    }

    /**
     * The return document of the commercial document of the sale: the returned lines plus the same share of its
     * discount lines at each VAT rate.
     *
     * @param  Collection<int, PosSaleItem>  $items
     * @param  list<array<string, mixed>>  $lines
     */
    private function returnDocument(PosSale $sale, $items, array $lines): ?CommercialDocument
    {
        $document = CommercialDocument::query()->where('pos_sale_id', $sale->id)->where('document_type', 'sale')->first();

        if ($document === null) {
            return null;
        }

        $document->load('lines');
        $used = [];
        $returned = [];
        $find = function (PosSaleItem $item) use ($document, &$used): ?CommercialDocumentLine {
            return $document->lines->first(static fn (CommercialDocumentLine $line): bool => ! in_array($line->id, $used, true)
                && $line->description === (string) $item->description
                && bccomp((string) $line->unit_price, (string) $item->unit_price, 4) === 0
                && bccomp((string) $line->tax_rate, (string) $item->tax_rate, 2) === 0);
        };
        $itemsById = $items->keyBy('id');

        foreach ($lines as $line) {
            $docLine = $find($itemsById->get($line['pos_sale_item_id']));

            if ($docLine === null) {
                continue;
            }

            $used[] = $docLine->id;
            $returned[$docLine->id] = ['line_id' => $docLine->id, 'quantity' => $line['quantity']];
        }

        $positive = $items->filter(static fn (PosSaleItem $item): bool => bccomp((string) $item->unit_price, '0', 4) > 0);

        foreach ($items->filter(static fn (PosSaleItem $item): bool => bccomp((string) $item->unit_price, '0', 4) < 0) as $discount) {
            $rate = bcadd((string) $discount->tax_rate, '0', 2);
            $base = $this->netAtRate($positive, $rate);
            $docLine = $find($discount);

            if ($docLine === null || bccomp($base, '0', 2) <= 0) {
                continue;
            }

            $used[] = $docLine->id;
            $open = bcsub((string) $docLine->quantity, (string) CommercialDocumentLine::query()->where('source_line_id', $docLine->id)->sum('quantity'), 4);
            $fraction = bcdiv($this->positiveNetReturned($lines, $rate), $base, 4);
            $quantity = bccomp($fraction, $open, 4) > 0 ? $open : $fraction;

            if (bccomp($quantity, '0', 4) > 0) {
                $returned[$docLine->id] = ['line_id' => $docLine->id, 'quantity' => $quantity];
            }
        }

        return $returned === [] ? null : $this->documents->return($document, array_values($returned));
    }

    /**
     * Net of the returned lines before the discount share, at a VAT rate (needed to scale the discount lines).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function positiveNetReturned(array $lines, string $rate): string
    {
        $net = '0.00';

        foreach ($lines as $line) {
            if (bccomp((string) $line['tax_rate'], $rate, 2) !== 0) {
                continue;
            }

            $item = PosSaleItem::query()->find($line['pos_sale_item_id']);
            $net = bcadd($net, bcmul((string) $item->line_total, bcdiv((string) $line['quantity'], (string) $item->quantity, 8), 6), 2);
        }

        return $net;
    }
}
