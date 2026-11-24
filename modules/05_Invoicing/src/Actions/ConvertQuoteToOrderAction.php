<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesQuote;

final class ConvertQuoteToOrderAction
{
    public function __construct(
        private readonly SalesQuoteServiceInterface $quoteService
    ) {}

    public function execute(SalesQuote $quote, string $userId): SalesOrder
    {
        return $this->quoteService->convertToOrder($quote, $userId);
    }
}
