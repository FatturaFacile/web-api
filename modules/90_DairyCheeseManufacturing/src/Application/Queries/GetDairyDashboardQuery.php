<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Queries;

final readonly class GetDairyDashboardQuery
{
    public function __construct(public ?string $date = null) {}
}
