<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The annual overwintering census declaration (BDA / VETINFO in Italy, cantonal veterinary office in Switzerland): a snapshot of the hives and nuclei per apiary.
 *
 * @property int $census_year
 * @property string $country
 * @property string $status
 * @property Carbon $window_start
 * @property Carbon $window_end
 * @property int $total_hives
 * @property int $total_nuclei
 * @property list<array<string, mixed>> $snapshot
 * @property Carbon $generated_at
 * @property Carbon|null $submitted_at
 * @property string|null $submission_reference
 */
final class CensusDeclaration extends BaseEnterpriseModel
{
    protected $table = 'bee_census_declarations';

    protected function casts(): array
    {
        return [
            'census_year' => 'integer',
            'window_start' => 'date',
            'window_end' => 'date',
            'total_hives' => 'integer',
            'total_nuclei' => 'integer',
            'snapshot' => 'array',
            'generated_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }
}
