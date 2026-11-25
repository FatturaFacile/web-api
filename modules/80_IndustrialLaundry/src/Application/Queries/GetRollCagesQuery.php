<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

final readonly class GetRollCagesQuery
{
    public function __construct(public ?string $search = null, public ?string $barcode = null, public int $perPage = 25) {}
}
