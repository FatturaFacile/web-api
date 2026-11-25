<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

use Modules\StatutoryFinancials\Models\StatutoryDossier;

final class GetDossierHandler
{
    public function handle(GetDossierQuery $query): StatutoryDossier
    {
        return StatutoryDossier::query()->with(['fiscalYear', 'comparativeYear', 'notes', 'package', 'artifacts'])->findOrFail($query->dossierId);
    }
}
