<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A month whose flat amount of a school bus contract is already invoiced.
 *
 * @property string $school_contract_id
 * @property string $period
 * @property string|null $invoice_id
 */
final class SchoolBusBilling extends BaseEnterpriseModel
{
    protected $table = 'ptr_school_bus_billings';
}
