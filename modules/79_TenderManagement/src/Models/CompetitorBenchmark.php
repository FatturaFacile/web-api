<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A historical award of a competitor (TenderCompetitorBenchmark): discount and scores by contracting authority and CPV, for
 * market intelligence and the access to the records (accesso agli atti).
 *
 * @property string|null $tender_id
 * @property string $competitor_name
 * @property string $contracting_authority_name
 * @property string|null $cpv_code
 * @property string|null $jurisdiction
 * @property Carbon|null $award_date
 * @property string|null $base_auction_amount
 * @property string|null $awarded_amount
 * @property string|null $offered_discount_percentage
 * @property string|null $technical_score
 * @property string|null $economic_score
 * @property string|null $total_score
 * @property bool $is_winner
 * @property string|null $source
 * @property string|null $notes
 */
final class CompetitorBenchmark extends BaseEnterpriseModel
{
    protected $table = 'tnd_competitor_benchmarks';

    protected function casts(): array
    {
        return [
            'award_date' => 'date',
            'base_auction_amount' => 'decimal:2',
            'awarded_amount' => 'decimal:2',
            'offered_discount_percentage' => 'decimal:4',
            'technical_score' => 'decimal:2',
            'economic_score' => 'decimal:2',
            'total_score' => 'decimal:2',
            'is_winner' => 'boolean',
        ];
    }
}
