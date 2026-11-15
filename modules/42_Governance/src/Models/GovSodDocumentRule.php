<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Per-document segregation of duties: whether the person who prepared a document of this type may also approve or
 * release it. A `block` rule refuses the action, a `warn` rule lets it through and writes it on the audit ledger.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_type
 * @property string $severity
 * @property bool $is_active
 */
final class GovSodDocumentRule extends BaseEnterpriseModel
{
    public const array Severities = ['warn', 'block'];

    protected $table = 'gov_sod_document_rules';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
