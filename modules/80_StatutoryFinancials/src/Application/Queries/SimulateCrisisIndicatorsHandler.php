<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\StatutoryGenerationService;

final class SimulateCrisisIndicatorsHandler
{
    public function __construct(private readonly StatutoryGenerationService $generation) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(SimulateCrisisIndicatorsQuery $query): array
    {
        return $this->generation->indicators(StatutoryDossier::query()->findOrFail($query->dossierId), $query->whatIf);
    }
}
