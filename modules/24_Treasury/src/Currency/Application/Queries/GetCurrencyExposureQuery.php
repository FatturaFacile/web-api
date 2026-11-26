<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Queries;

final readonly class GetCurrencyExposureQuery
{
    public function __construct(public string $tenantId, public string $asOf) {}
}
