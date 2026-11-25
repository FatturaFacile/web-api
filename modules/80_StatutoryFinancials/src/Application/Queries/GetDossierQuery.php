<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

/**
 * The full state of a dossier: statements, rounding quadrature, reclassification report, notes, package and artifacts.
 */
final readonly class GetDossierQuery
{
    public function __construct(public string $dossierId) {}
}
