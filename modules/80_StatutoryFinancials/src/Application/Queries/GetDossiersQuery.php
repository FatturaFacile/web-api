<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

final readonly class GetDossiersQuery
{
    public function __construct(
        public ?string $status = null,
        public ?string $fiscalYearId = null,
        public ?string $standard = null,
        public int $perPage = 25,
    ) {}
}
