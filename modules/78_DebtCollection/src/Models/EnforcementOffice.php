<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A Swiss debt enforcement office (Betreibungsamt) and the postal code range it covers.
 *
 * @property string $name
 * @property string|null $postal_code_from
 * @property string|null $postal_code_to
 * @property string|null $address
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $canton
 * @property string|null $email
 */
final class EnforcementOffice extends BaseEnterpriseModel
{
    protected $table = 'dc_enforcement_offices';
}
