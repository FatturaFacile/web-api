<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\ConventionService;

/**
 * Hashes a list of matricole, badges or cards with SHA-256 and stores only the hashes.
 */
final class ImportConventionHashedIdentifiersHandler
{
    public function __construct(
        private readonly ConventionService $conventions,
    ) {}

    /**
     * @return array{received: int, imported: int, duplicates: int, invalid: int, preview: list<array{hint: string, sha256: string}>}
     */
    public function handle(ImportConventionHashedIdentifiersCommand $command): array
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($command->conventionId);

        return $this->conventions->importIdentifiers($convention, $command->identifiers, $command->dryRun, $command->userId);
    }
}
