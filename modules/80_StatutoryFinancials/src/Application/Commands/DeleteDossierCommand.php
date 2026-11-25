<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

final readonly class DeleteDossierCommand
{
    public function __construct(public string $dossierId) {}
}
