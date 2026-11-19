<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Queries;

use App\Models\Privacy\DataBreachIncident;
use App\Services\Privacy\Governance\BreachIncidentService;
use Illuminate\Support\Collection;

final readonly class GetDataBreach72hCountdownHandler
{
    public function __construct(private BreachIncidentService $breaches) {}

    /**
     * @return Collection<int, DataBreachIncident>
     */
    public function handle(GetDataBreach72hCountdownQuery $query): Collection
    {
        return $this->breaches->activeCountdown($query->tenantId);
    }
}
