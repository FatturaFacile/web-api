<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

final readonly class UpsertCustomer360DossierCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public array $payload,
        public ?string $dossierId = null,
    ) {}
}
