<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

/**
 * Dimensional threshold check (total assets, revenue, average employees) of a dossier, optionally for another scheme than the chosen one.
 */
final readonly class CheckEligibilityQuery
{
    public function __construct(public string $dossierId, public ?string $standard = null) {}
}
