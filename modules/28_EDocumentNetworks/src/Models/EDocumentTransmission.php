<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Eloquent model of the edocument transmission (tenant scoped where it has a tenant_id).
 */
final class EDocumentTransmission extends BaseEnterpriseModel
{
    protected $table = 'edocument_transmissions';
}
