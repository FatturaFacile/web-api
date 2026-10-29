<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Quote;
use App\Models\SalesOrder;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertQuoteToOrder
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentEventRecorder $events,
    ) {}

    public function execute(Quote $quote): SalesOrder
    {
        return DB::transaction(function () use ($quote): SalesOrder {
            $lockedQuote = Quote::where('id', $quote->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedQuote->status, ['rejected', 'expired'], true)) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_not_convertible')]);
            }

            if (SalesOrder::where('quote_id', $lockedQuote->id)->exists()) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_already_converted')]);
            }

            $number = $this->numbering->next('sales_order');

            $order = SalesOrder::create([
                'tenant_id' => $lockedQuote->tenant_id,
                'contact_id' => $lockedQuote->contact_id,
                'quote_id' => $lockedQuote->id,
                'order_number' => $number['number'],
                'date' => now()->toDateString(),
                'currency' => $lockedQuote->currency,
                'total_net' => $lockedQuote->total_net,
                'total_tax' => $lockedQuote->total_tax,
                'total_gross' => $lockedQuote->total_gross,
                'status' => 'confirmed',
                'notes' => $lockedQuote->notes,
            ]);

            foreach ($lockedQuote->items as $item) {
                $order->items()->create($item->only([
                    'product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'discount_percentage', 'line_total',
                ]));
            }

            $lockedQuote->update(['status' => 'accepted']);
            $this->events->record($lockedQuote, 'converted_to_order', ['sales_order_id' => $order->id]);

            return $order->load('items');
        });
    }
}
