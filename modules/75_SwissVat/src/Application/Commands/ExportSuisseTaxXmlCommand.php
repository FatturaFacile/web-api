<?php

declare(strict_types=1);

namespace Modules\SwissVat\Application\Commands;

final readonly class ExportSuisseTaxXmlCommand
{
    public function __construct(
        public string $tenantId,
        public string $returnId,
        public ?string $actorUserId,
    ) {}
}
