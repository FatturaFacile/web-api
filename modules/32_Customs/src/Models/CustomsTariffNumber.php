<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

final class CustomsTariffNumber extends CustomsMasterRecord
{
    use AllowsGlobalRecords, BelongsToTenant;

    protected $table = 'customs_tariff_numbers';
}
