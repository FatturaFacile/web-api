<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\QuoteStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\QuoteLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Models\SalesQuote;

/**
 * Converts a quote into a confirmed order from the sales API, through the one quote-to-order writer of the invoicing
 * module (SalesQuoteService::convertToOrder): the order is numbered on the order series, priced from the quote lines
 * and then confirmed behind the credit check of the company, so it can end on credit hold.
 */
class ConvertQuoteToOrder
{
    public function __construct(
        private readonly SalesQuoteServiceInterface $quotes,
        private readonly SalesOrderServiceInterface $orders,
        private readonly QuoteLifecycleService $lifecycle,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * @throws ValidationException when the quote cannot be converted (status, validity, already converted)
     */
    public function execute(Quote $quote, ?string $userId = null): SalesOrder
    {
        return DB::transaction(function () use ($quote, $userId): SalesOrder {
            $lockedQuote = Quote::where('id', $quote->id)->lockForUpdate()->firstOrFail();

            if (! QuoteStatus::allows($lockedQuote->status, QuoteStatus::Accepted)) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_not_convertible')]);
            }

            $this->lifecycle->assertWithinValidity($lockedQuote);

            try {
                $moduleOrder = $this->quotes->convertToOrder(SalesQuote::query()->findOrFail($lockedQuote->id), $userId ?? 'system');
            } catch (BusinessRuleException $exception) {
                throw ValidationException::withMessages(['quote' => $exception->getMessage()]);
            }

            $this->orders->submitOrder($moduleOrder, $userId ?? 'system');
            $this->events->record($lockedQuote, 'converted_to_order', ['sales_order_id' => $moduleOrder->id]);

            return SalesOrder::query()->with(['items', 'contact'])->findOrFail($moduleOrder->id);
        });
    }
}
