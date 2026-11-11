<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\Foundation\Concerns\Auditable;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * The legal values of a topic for a year, with the document they come from. Generic rows hold what was verified; a
 * company row overrides them for that company.
 */
final class PayrollLegalParameter extends PayrollRecord
{
    use AllowsGlobalRecords, Auditable, BelongsToTenant;

    protected $table = 'payroll_legal_parameters';

    protected function casts(): array
    {
        return ['year' => 'integer', 'parameters' => 'array'];
    }
}
