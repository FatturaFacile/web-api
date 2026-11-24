<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

final class CustomsCountry extends CustomsMasterRecord
{
    use AllowsGlobalRecords;

    protected $table = 'customs_countries';
}
