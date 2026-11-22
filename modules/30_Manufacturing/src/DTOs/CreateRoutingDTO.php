<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

/**
 * Data of a new routing with its operations. Times are minutes as decimal strings.
 */
final readonly class CreateRoutingDTO
{
    /**
     * @param  list<array<string, mixed>>  $operations
     */
    public function __construct(
        public string $tenantId,
        public string $mfgItemId,
        public string $name,
        public array $operations,
        public string $revision = 'A',
        public bool $isDefault = true,
        public ?string $effectiveFrom = null,
        public ?string $effectiveTo = null,
        public ?string $notes = null,
        public ?string $bomId = null,
    ) {}
}
