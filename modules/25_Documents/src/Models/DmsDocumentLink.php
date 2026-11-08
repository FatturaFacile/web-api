<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Eloquent model of the dms document link (tenant scoped where it has a tenant_id).
 */
final class DmsDocumentLink extends BaseEnterpriseModel
{
    protected $table = 'dms_document_links';
}
