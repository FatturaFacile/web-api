<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Keeps the audit columns every table carries (deleted_at and the three audit users) out of the serialized model, for
 * models that an endpoint returns as they are and whose documented response does not list them.
 */
trait HidesAuditColumns
{
    protected function initializeHidesAuditColumns(): void
    {
        $this->makeHidden(['deleted_at', 'created_by', 'updated_by', 'deleted_by']);
    }
}
