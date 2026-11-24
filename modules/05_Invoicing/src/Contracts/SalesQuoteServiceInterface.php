<?php

declare(strict_types=1);

namespace Modules\Invoicing\Contracts;

use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesQuote;

interface SalesQuoteServiceInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createQuote(array $data, array $items): SalesQuote;

    public function submitForApproval(SalesQuote $quote, string $userId): SalesQuote;

    public function approveQuote(SalesQuote $quote, string $userId): SalesQuote;

    public function rejectQuote(SalesQuote $quote, string $userId, ?string $reason = null): SalesQuote;

    public function hasActiveApprovalWorkflow(SalesQuote $quote): bool;

    public function approveWithoutWorkflow(SalesQuote $quote, string $userId): SalesQuote;

    public function rejectWithoutWorkflow(SalesQuote $quote, string $userId, ?string $reason = null): SalesQuote;

    public function acceptQuote(SalesQuote $quote): SalesQuote;

    public function convertToOrder(SalesQuote $quote, string $userId): SalesOrder;
}
