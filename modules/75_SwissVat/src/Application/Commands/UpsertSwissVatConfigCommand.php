<?php

declare(strict_types=1);

namespace Modules\SwissVat\Application\Commands;

/**
 * CQRS command to update a tenant's Swiss VAT regime & settings.
 */
final readonly class UpsertSwissVatConfigCommand
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $tenantId,
        public ?string $actorUserId,
        public array $attributes,
    ) {}
}
