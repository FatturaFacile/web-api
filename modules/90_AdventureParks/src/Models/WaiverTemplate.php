<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Legal text of the liability waiver in one language and version. Global rows (tenant_id null) are the standard text; a company can
 * publish its own version of the same id.
 *
 * @property string $version_id
 * @property string $locale
 * @property string $title
 * @property list<string> $paragraphs
 * @property list<string> $rules
 */
final class WaiverTemplate extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'adv_waiver_templates';

    protected function casts(): array
    {
        return [
            'paragraphs' => 'array',
            'rules' => 'array',
        ];
    }
}
