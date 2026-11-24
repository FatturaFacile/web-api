<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A negotiation round of a contract: opened with a draft sent out, closed with an outcome.
 *
 * @property string $contract_kind
 * @property string $contract_id
 * @property int $round_no
 * @property string $status
 * @property string|null $outcome
 * @property string|null $summary
 * @property string|null $opened_by
 * @property Carbon|null $closed_at
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
