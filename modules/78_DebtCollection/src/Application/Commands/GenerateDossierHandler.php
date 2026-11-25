<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

use Illuminate\Support\Carbon;
use Modules\DebtCollection\Models\Dossier;
use Modules\DebtCollection\Services\DossierService;

final class GenerateDossierHandler
{
    public function __construct(
        private DossierService $dossiers,
    ) {}

    public function handle(GenerateDossierCommand $command): Dossier
    {
        return $this->dossiers->generate($command->tenantId, $command->customerId, $command->itemIds, $command->officeId, $command->locale, Carbon::parse($command->asOf), $command->actorId);
    }
}
