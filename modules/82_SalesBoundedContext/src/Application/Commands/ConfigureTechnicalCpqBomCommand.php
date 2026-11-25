<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

final readonly class ConfigureTechnicalCpqBomCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public array $payload,
    ) {}
}
