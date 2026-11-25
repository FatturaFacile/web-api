<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\StatutoryGenerationService;

final class GenerateStatutoryBalanceWithRoundingQuadratureHandler
{
    public function __construct(private readonly StatutoryGenerationService $generation) {}

    public function handle(GenerateStatutoryBalanceWithRoundingQuadratureCommand $command): StatutoryDossier
    {
        return $this->generation->generateStatements(StatutoryDossier::query()->findOrFail($command->dossierId));
    }
}
