<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A payee for 1099 reporting. The tax identification number is stored encrypted and never serialized.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $contact_id
 * @property string $name
 * @property string|null $business_name
 * @property string $tin_type
 * @property string|null $tin
 * @property string|null $tin_last4
 * @property string|null $address_line
 * @property string|null $city
 * @property string|null $state
 * @property string|null $zip
 * @property string $default_form
 * @property bool $is_exempt
 * @property Carbon|null $w9_received_on
 */
final class Us1099Recipient extends BaseEnterpriseModel
{
    public const array Forms = ['1099-NEC', '1099-MISC'];

    public const array TinTypes = ['ein', 'ssn', 'itin'];

    protected $table = 'us_1099_recipients';

    protected $hidden = ['tin'];

    protected function casts(): array
    {
        return [
            'tin' => 'encrypted',
            'is_exempt' => 'boolean',
            'w9_received_on' => 'date',
        ];
    }

    /**
     * @return HasMany<Us1099Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Us1099Payment::class, 'recipient_id');
    }
}
