<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Queries;

final readonly class TraceLineageQuery
{
    public function __construct(public string $direction, public string $type, public ?string $id, public ?string $serial = null) {}
}
