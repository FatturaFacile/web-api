<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Queries;

final readonly class GetCurrencySettingsQuery
{
    public function __construct(public string $tenantId) {}
}
