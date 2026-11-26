<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Queries;

final readonly class ListMigrationJobsQuery
{
    public function __construct(
        public ?string $status = null,
        public ?string $sourceSystem = null,
        public ?string $legalEntityId = null,
        public int $limit = 50,
    ) {}
}
