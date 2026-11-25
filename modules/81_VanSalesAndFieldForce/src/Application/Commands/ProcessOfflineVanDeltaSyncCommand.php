<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Commands;

final readonly class ProcessOfflineVanDeltaSyncCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public array $payload,
    ) {}
}
