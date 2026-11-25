<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

final readonly class TransitionDossierCommand
{
    public function __construct(public string $dossierId, public string $target, public ?string $reference = null) {}
}
