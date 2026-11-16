<?php

declare(strict_types=1);

namespace App\Events\Mobile;

use App\Models\PayslipDocument;
use Illuminate\Foundation\Events\Dispatchable;

/** Read receipt: the employee opened a payslip / CU / Lohnausweis. */
final class PayslipRead
{
    use Dispatchable;

    public function __construct(public readonly PayslipDocument $document) {}
}
