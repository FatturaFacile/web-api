<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed>|list<array<string, mixed>>|null $default_wbs_structure
 * @property bool $is_active
 */
final class ProjectTemplate extends BaseEnterpriseModel
{
    protected $table = 'project_templates';

    protected function casts(): array
    {
        return [
            'default_wbs_structure' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
