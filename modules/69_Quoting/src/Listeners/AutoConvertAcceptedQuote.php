<?php

declare(strict_types=1);

namespace Modules\Quoting\Listeners;

use Modules\Quoting\Application\Commands\ConvertQuoteToSalesOrderCommand;
use Modules\Quoting\Application\Handlers\ConvertQuoteToSalesOrderHandler;
use Modules\Quoting\Domain\Enums\ConversionTarget;
use Modules\Quoting\Domain\Events\QuoteSignedAndAccepted;
use Modules\Quoting\Infrastructure\Models\CpqQuote;

/**
 * Auto-conversion mode: a valid signature immediately becomes a sales order when the quote asks for it. The conversion is
 * idempotent, so a redelivered event cannot create a second order.
 */
final class AutoConvertAcceptedQuote
{
    public function __construct(private readonly ConvertQuoteToSalesOrderHandler $converter) {}

    public function handle(QuoteSignedAndAccepted $event): void
    {
        $quote = CpqQuote::query()->find($event->quoteId);

        if ($quote instanceof CpqQuote && $quote->auto_convert && $quote->auto_convert_target === ConversionTarget::SalesOrder && $quote->contact_id !== null) {
            $this->converter->handle(new ConvertQuoteToSalesOrderCommand($quote->id, null));
        }
    }
}
