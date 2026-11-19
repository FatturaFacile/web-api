<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Queries;

final readonly class GetRopaRegistryExportQuery
{
    public function __construct(public string $tenantId, public string $format = 'pdf') {}
}
