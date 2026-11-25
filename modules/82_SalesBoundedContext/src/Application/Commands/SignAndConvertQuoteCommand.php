<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

final readonly class SignAndConvertQuoteCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public string $action,
        public array $payload = [],
        public ?string $quoteId = null,
    ) {}
}
