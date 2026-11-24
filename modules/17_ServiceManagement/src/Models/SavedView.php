<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\ServiceManagement\Models\Concerns\SharedOrPersonal;

/**
 * A saved set of ticket list filters.
 *
 * @property array<string, mixed> $filters
 */
final class SavedView extends BaseEnterpriseModel
{
    use SharedOrPersonal;

    protected $table = 'esm_saved_views';

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'filters' => 'array',
            'position' => 'integer',
        ];
    }
}
