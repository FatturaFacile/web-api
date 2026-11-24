<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use Database\Factories\BiCalculatedMeasureFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * A measure defined by a company on a dataset of the semantic layer as an arithmetic formula over the base measures of
 * that dataset (for example `net_amount / document_count`). The formula is parsed by the safe formula evaluator and is
 * never executed as SQL: it is computed on the aggregated rows.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $dataset
 * @property string $key
 * @property string $label
 * @property string $formula
 * @property string $unit
 * @property int $scale
 * @property string|null $created_by
 */
#[UseFactory(BiCalculatedMeasureFactory::class)]
final class BiCalculatedMeasure extends BaseEnterpriseModel
{
    protected $table = 'bi_calculated_measures';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['scale' => 'integer'];
    }
}
