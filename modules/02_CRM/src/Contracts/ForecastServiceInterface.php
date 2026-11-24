<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Modules\CRM\DTOs\GenerateForecastDTO;
use Modules\CRM\Models\Forecast;

interface ForecastServiceInterface
{
    public function generateForecast(GenerateForecastDTO $dto, string $actorUserId): Forecast;

    public function lockForecast(Forecast $forecast, string $actorUserId): Forecast;
}
