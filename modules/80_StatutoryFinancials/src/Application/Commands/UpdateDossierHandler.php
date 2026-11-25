<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\DossierService;

final class UpdateDossierHandler
{
    public function __construct(private readonly DossierService $dossiers) {}

    public function handle(UpdateDossierCommand $command): StatutoryDossier
    {
        return $this->dossiers->update(StatutoryDossier::query()->findOrFail($command->dossierId), $command->data, $command->userId);
    }
}
