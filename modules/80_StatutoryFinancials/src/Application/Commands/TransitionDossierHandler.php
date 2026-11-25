<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Modules\StatutoryFinancials\Domain\Enums\DossierStatus;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\DossierService;

final class TransitionDossierHandler
{
    public function __construct(private readonly DossierService $dossiers) {}

    public function handle(TransitionDossierCommand $command): StatutoryDossier
    {
        return $this->dossiers->transition(StatutoryDossier::query()->findOrFail($command->dossierId), DossierStatus::from($command->target), $command->reference);
    }
}
