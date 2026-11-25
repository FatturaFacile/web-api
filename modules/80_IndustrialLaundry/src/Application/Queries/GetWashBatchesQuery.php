<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

final readonly class GetWashBatchesQuery
{
    public function __construct(public ?string $status = null, public ?string $from = null, public ?string $to = null, public int $perPage = 25) {}
}
