<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

/**
 * Creates a draft convention with its slug, partner customer and discount matrix.
 */
final readonly class CreateCorporateConventionCommand
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $data,
        public string $tenantId,
        public ?string $userId = null,
    ) {}
}
