<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\Foundation\Concerns\Auditable;

/**
 * The legal values of a topic for a year, with the document they come from. Generic rows hold what was verified; a
 * company row overrides them for that company.
 */
final class PayrollLegalParameter extends PayrollRecord
{
    use Auditable;

    protected $table = 'payroll_legal_parameters';

    protected function casts(): array
    {
        return ['year' => 'integer', 'parameters' => 'array'];
    }
}
