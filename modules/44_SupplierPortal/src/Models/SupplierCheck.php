<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A verification of a supplier: VAT number validity (VIES), VAT status (a blocked VAT position), anti-mafia
 * certificate, sanctions lists, business register (Italian visura, Swiss Handelsregister/UID), online DURC.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_id
 * @property string $check_type
 * @property string $result
 * @property Carbon|null $checked_at
 * @property Carbon|null $valid_until
 * @property string|null $checked_by
 * @property string|null $notes
 */
final class SupplierCheck extends BaseEnterpriseModel
{
    public const array Types = ['vies_vat', 'vat_status', 'antimafia', 'sanctions', 'business_register', 'durc_online', 'other'];

    public const array Results = ['pending', 'passed', 'failed'];

    protected $table = 'sp_supplier_checks';

    protected function casts(): array
    {
        return ['checked_at' => 'date', 'valid_until' => 'date'];
    }
}
