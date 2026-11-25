<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

/**
 * Hashes a list of matricole, badges or cards with SHA-256 and stores only the hashes.
 */
final readonly class ImportConventionHashedIdentifiersCommand
{
    public function __construct(
        public string $conventionId,
        /** @var list<string> */
        public array $identifiers,
        public bool $dryRun = false,
        public ?string $userId = null,
    ) {}
}
