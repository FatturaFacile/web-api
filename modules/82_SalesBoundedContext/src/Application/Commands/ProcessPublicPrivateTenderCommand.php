<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

final readonly class ProcessPublicPrivateTenderCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public array $payload,
        public ?string $tenderId = null,
        public bool $convertToContract = false,
    ) {}
}
