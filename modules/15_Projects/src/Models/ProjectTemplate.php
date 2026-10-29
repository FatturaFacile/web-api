<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

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
