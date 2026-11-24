<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Base of the audited records of the module. Personal and health data use encrypted casts: the audit trait writes the
 * raw attributes, so the ledger only ever receives ciphertext, as for the employees of the HR module, and the column
 * names holding identifiers are redacted by the ledger itself.
 */
abstract class AssociationRecord extends BaseEnterpriseModel
{
    /**
     * Generic rows (no tenant) are shared by every company and read-only for them.
     */
    public function isGeneric(): bool
    {
        return $this->tenant_id === null;
    }
}
