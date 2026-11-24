<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Models\SalesQuote;

final class AcceptSalesQuoteAction
{
    public function __construct(
        private readonly SalesQuoteServiceInterface $quoteService
    ) {}

    public function execute(SalesQuote $quote): SalesQuote
    {
        return $this->quoteService->acceptQuote($quote);
    }
}
