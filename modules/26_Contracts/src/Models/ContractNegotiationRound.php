<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A negotiation round of a contract: opened with a draft sent out, closed with an outcome.
 */
final class ContractNegotiationRound extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Outcomes = ['agreed', 'countered', 'rejected', 'withdrawn'];

    protected $table = 'contract_negotiation_rounds';

    protected function casts(): array
    {
        return ['round_no' => 'integer', 'closed_at' => 'datetime'];
    }
}
