<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

/**
 * Builds the .xbrl instance of the dossier for the configured taxonomy, runs the structural and cross-statement checks, records the
 * validation report (tebeni_validation_report) and stores the exported artifact.
 */
final readonly class ValidateAndExportXbrlInstanceCommand
{
    public function __construct(public string $dossierId, public ?string $userId = null) {}
}
