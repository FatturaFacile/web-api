<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A weaker wording a negotiator may fall back to when the counterparty rejects the clause; rank 1 is the first fallback.
 */
final class ContractClauseFallback extends BaseEnterpriseModel
{
    protected $table = 'contract_clause_fallbacks';

    protected function casts(): array
    {
        return ['rank' => 'integer', 'requires_approval' => 'boolean'];
    }
}
