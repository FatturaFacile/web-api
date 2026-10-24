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
        if (in_array($quote->status, ['rejected', 'expired'], true)) {
            throw ValidationException::withMessages(['quote' => __('sales.quote_not_convertible')]);
        }

        if (SalesOrder::where('quote_id', $quote->id)->exists()) {
            throw ValidationException::withMessages(['quote' => __('sales.quote_already_converted')]);
        }

        return DB::transaction(function () use ($quote): SalesOrder {
            $number = $this->numbering->next('sales_order');

            $order = SalesOrder::create([
                'tenant_id' => $quote->tenant_id,
                'contact_id' => $quote->contact_id,
                'quote_id' => $quote->id,
                'order_number' => $number['number'],
                'date' => now()->toDateString(),
                'currency' => $quote->currency,
                'total_net' => $quote->total_net,
                'total_tax' => $quote->total_tax,
                'total_gross' => $quote->total_gross,
                'status' => 'confirmed',
                'notes' => $quote->notes,
            ]);

            foreach ($quote->items as $item) {
                $order->items()->create($item->only([
                    'product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'discount_percentage', 'line_total',
                ]));
            }

            $quote->update(['status' => 'accepted']);
            $this->events->record($quote, 'converted_to_order', ['sales_order_id' => $order->id]);

            return $order->load('items');
        });
    }
}
