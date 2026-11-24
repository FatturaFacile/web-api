<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A type of document the company requires from its suppliers (DURC, chamber of commerce extract, insurance, Swiss UID
 * extract, ISO certificates...), optionally for the suppliers of one country only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $country
 * @property bool $is_mandatory
 * @property bool $requires_expiry
 * @property int|null $validity_days
 * @property int $alert_days
 * @property bool $is_active
 * @property int $sort_order
 */
final class DocumentRequirement extends BaseEnterpriseModel
{
    protected $table = 'sp_document_requirements';

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'requires_expiry' => 'boolean',
            'validity_days' => 'integer',
            'alert_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<SupplierDocumentSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(SupplierDocumentSubmission::class, 'requirement_id');
    }
}
