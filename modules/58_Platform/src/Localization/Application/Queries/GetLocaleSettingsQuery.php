<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

final readonly class GetLocaleSettingsQuery
{
    public function __construct(public string $tenantId) {}
}
