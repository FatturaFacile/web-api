<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

final readonly class GetDormantGarmentsQuery
{
    public function __construct(public ?string $customerId = null, public ?int $days = null, public ?string $asOf = null) {}
}
