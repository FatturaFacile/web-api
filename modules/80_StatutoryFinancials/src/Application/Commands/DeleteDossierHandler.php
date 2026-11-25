<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\DossierService;

final class DeleteDossierHandler
{
    public function __construct(private readonly DossierService $dossiers) {}

    public function handle(DeleteDossierCommand $command): void
    {
        $this->dossiers->delete(StatutoryDossier::query()->findOrFail($command->dossierId));
    }
}
