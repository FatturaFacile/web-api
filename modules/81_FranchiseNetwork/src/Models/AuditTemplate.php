<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Store audit checklist template with scored criteria. Presets have a null tenant.
 *
 * @property string $code
 * @property array<string, mixed> $labels
 * @property list<array<string, mixed>> $criteria
 * @property string $pass_threshold_percent
 * @property bool $is_active
 */
final class AuditTemplate extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'frn_audit_templates';

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'criteria' => 'array',
            'pass_threshold_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
