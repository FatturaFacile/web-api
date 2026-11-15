<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A review comment on a document version, optionally anchored to a line.
 */
final class ContractComment extends BaseEnterpriseModel
{
    protected $table = 'contract_comments';

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'resolved_at' => 'datetime'];
    }
}
