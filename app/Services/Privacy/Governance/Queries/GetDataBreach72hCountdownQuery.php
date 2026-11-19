<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Queries;

final readonly class GetDataBreach72hCountdownQuery
{
    public function __construct(public string $tenantId) {}
}
