<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A notice text per code and language; global rows are the seeded defaults.
 *
 * @property string $code
 * @property string $locale
 * @property string|null $jurisdiction
 * @property string $subject
 * @property string $body
 */
final class Template extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'dc_templates';
}
