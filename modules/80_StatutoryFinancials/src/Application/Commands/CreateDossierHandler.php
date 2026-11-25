<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\DossierService;

final class CreateDossierHandler
{
    public function __construct(private readonly DossierService $dossiers) {}

    public function handle(CreateDossierCommand $command): StatutoryDossier
    {
        return $this->dossiers->create($command->data, $command->tenantId, $command->userId);
    }
}
