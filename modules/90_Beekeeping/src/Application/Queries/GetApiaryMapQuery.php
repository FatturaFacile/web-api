<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Queries;

final readonly class GetApiaryMapQuery
{
    public function __construct(public bool $onlyActive = true) {}
}
