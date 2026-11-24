<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Eloquent model of the dms tag (tenant scoped where it has a tenant_id).
 */
final class DmsTag extends BaseEnterpriseModel
{
    protected $table = 'dms_tags';
}
