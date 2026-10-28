<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Services\Inventory\StockService;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertQuoteToDeliveryNote
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentEventRecorder $events,
        private readonly StockService $stock,
    ) {}

    /**
     * Create a delivery note straight from a quote (skipping the order step).
     */
    public function execute(Quote $quote): DeliveryNote
    {
        return DB::transaction(function () use ($quote): DeliveryNote {
            $lockedQuote = Quote::where('id', $quote->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedQuote->status, ['rejected', 'expired'], true)) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_not_convertible')]);
            }

            $note = DeliveryNote::create([
                'tenant_id' => $lockedQuote->tenant_id,
                'contact_id' => $lockedQuote->contact_id,
                'ddt_number' => $this->numbering->next('delivery_note')['number'],
                'date' => now()->toDateString(),
                'status' => 'issued',
                'notes' => $lockedQuote->notes,
            ]);

            foreach ($lockedQuote->items as $item) {
                $note->items()->create([
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                ]);
            }

            $this->stock->recordForLines('out', $note, $note->items()->get());
            $lockedQuote->update(['status' => 'accepted']);
            $this->events->record($lockedQuote, 'converted_to_delivery_note', ['delivery_note_id' => $note->id]);

            return $note->load('items');
        });
    }
}
