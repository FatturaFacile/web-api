<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Queries;

final readonly class ListMigrationTemplatesQuery
{
    public function __construct(
        public ?string $sourceSystem = null,
        public ?string $entityType = null,
    ) {}
}
