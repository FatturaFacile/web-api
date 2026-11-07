<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use App\Services\Tenancy\TenantContext;
use Modules\Associations\Support\BlindIndex;

/**
 * A person or organization that gives to the association. Identity and contact data are encrypted; the tax code can
 * be matched through its keyed hash.
 *
 * @property string $id
 * @property string $kind
 * @property string $name
 * @property ?string $tax_code
 * @property ?string $address
 */
final class Donor extends AssociationRecord
{
    protected $table = 'assoc_donors';

    protected $hidden = ['tax_code_hash'];

    protected static function booted(): void
    {
        self::saving(static function (self $donor): void {
            if ($donor->isDirty('tax_code')) {
                $donor->tax_code_hash = $donor->tax_code === null ? null : BlindIndex::taxCode($donor->tax_code, (string) ($donor->tenant_id ?? app(TenantContext::class)->getTenantId()));
            }
        });
    }

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'tax_code' => 'encrypted', 'email' => 'encrypted', 'address' => 'encrypted', 'notes' => 'encrypted'];
    }
}
