<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

final readonly class UpdateDossierCommand
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public string $dossierId, public array $data, public ?string $userId) {}
}
