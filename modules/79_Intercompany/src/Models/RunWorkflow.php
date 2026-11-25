<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Guided workflow state of a consolidation run, with an optimistic version.
 *
 * @property int $version
 */
final class RunWorkflow extends BaseEnterpriseModel
{
    protected $table = 'ic_run_workflows';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }
}
