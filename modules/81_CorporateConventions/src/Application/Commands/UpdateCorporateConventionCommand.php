<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

/**
 * Updates a convention and, when given, replaces its discount matrix.
 */
final readonly class UpdateCorporateConventionCommand
{
    public function __construct(
        public string $conventionId,
        /** @var array<string, mixed> */
        public array $data,
        public ?string $userId = null,
    ) {}
}
