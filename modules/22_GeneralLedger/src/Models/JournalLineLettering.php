<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The lettering of a posted ledger line: lines of one account (and partner) whose debits and credits cancel out share a
 * code. The posted line is immutable, so the lettering lives here (FEC columns EcritureLet and DateLet).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $journal_line_id
 * @property string $account_id
 * @property string $lettering_code
 * @property Carbon $lettered_on
 */
final class JournalLineLettering extends BaseEnterpriseModel
{
    protected $table = 'journal_line_letterings';

    protected function casts(): array
    {
        return ['lettered_on' => 'date'];
    }
}
