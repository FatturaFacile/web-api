<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\QuoteStatus;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\QuoteLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;

/**
 * Ships an accepted quote straight away, without an order: the delivery note is created and issued by the one delivery
 * note writer (DeliveryFulfillmentService), which moves the stock.
 */
class ConvertQuoteToDeliveryNote
{
    public function __construct(
        private readonly DeliveryFulfillmentServiceInterface $fulfillment,
        private readonly DocumentEventRecorder $events,
        private readonly QuoteLifecycleService $lifecycle,
    ) {}

    /**
     * Create a delivery note straight from a quote (skipping the order step).
     *
     * @param  array<string, mixed>  $attributes  transport data of the note
     */
    public function execute(Quote $quote, array $attributes = [], ?string $userId = null): DeliveryNote
    {
        return DB::transaction(function () use ($quote, $attributes, $userId): DeliveryNote {
            $lockedQuote = Quote::where('id', $quote->id)->lockForUpdate()->firstOrFail();

            if (! QuoteStatus::allows($lockedQuote->status, QuoteStatus::Accepted)) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_not_convertible')]);
            }

            $this->lifecycle->assertWithinValidity($lockedQuote);

            $note = $this->fulfillment->createStandaloneDeliveryNote(
                (string) $lockedQuote->tenant_id,
                (string) $lockedQuote->contact_id,
                $attributes + ['notes' => $lockedQuote->notes, 'created_by' => $userId],
                $lockedQuote->items->map(static fn ($item): array => [
                    'product_id' => $item->product_id,
                    'description' => (string) $item->description,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'tax_rate' => (string) $item->tax_rate,
                ])->values()->all()
            );
            $this->fulfillment->dispatchDelivery($note, $userId ?? 'system');

            QuoteStatus::assertTransition($lockedQuote->status, QuoteStatus::Accepted);
            $lockedQuote->update(['status' => QuoteStatus::Accepted->value]);
            $this->events->record($lockedQuote, 'converted_to_delivery_note', ['delivery_note_id' => $note->id]);

            return ConvertOrderToDeliveryNote::appNote($note);
        });
    }
}
