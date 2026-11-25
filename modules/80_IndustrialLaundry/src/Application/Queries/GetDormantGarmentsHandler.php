<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Services\DormantGarmentService;

final class GetDormantGarmentsHandler
{
    public function __construct(private readonly DormantGarmentService $dormant) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(GetDormantGarmentsQuery $query): array
    {
        return $this->dormant->list($query->customerId, $query->days, $query->asOf === null ? null : Carbon::parse($query->asOf));
    }
}
