<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Company settings: interception switch and the ledger accounts used for the transitory foreign VAT receivable and the non
 * recoverable foreign VAT expense (created on first use when not chosen).
 *
 * @property bool $auto_intercept
 * @property string|null $receivable_account_id
 * @property string|null $non_recoverable_expense_account_id
 */
final class ForeignVatSetting extends BaseEnterpriseModel
{
    protected $table = 'foreign_vat_settings';

    protected function casts(): array
    {
        return ['auto_intercept' => 'boolean'];
    }
}
