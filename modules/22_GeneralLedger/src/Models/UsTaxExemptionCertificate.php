<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A sales tax exemption certificate of a customer: valid in a state (every jurisdiction of it, or only the one named)
 * from the issue date until it expires.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property string|null $jurisdiction_id
 * @property string $state
 * @property string $certificate_number
 * @property string $reason
 * @property Carbon $issued_on
 * @property Carbon|null $expires_on
 * @property bool $is_active
 * @property string|null $notes
 */
final class UsTaxExemptionCertificate extends BaseEnterpriseModel
{
    public const array Reasons = ['resale', 'government', 'nonprofit', 'manufacturing', 'agricultural', 'other'];

    protected $table = 'us_tax_exemption_certificates';

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date', 'is_active' => 'boolean'];
    }
}
