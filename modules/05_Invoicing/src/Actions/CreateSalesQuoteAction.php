<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Models\SalesQuote;

final class CreateSalesQuoteAction
{
    public function __construct(
        private readonly SalesQuoteServiceInterface $quoteService
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $items
     */
    public function execute(array $data, array $items): SalesQuote
    {
        return $this->quoteService->createQuote($data, $items);
    }
}
