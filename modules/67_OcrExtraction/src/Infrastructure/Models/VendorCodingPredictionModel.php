<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * What the accountant coded for a recurring kind of line of one vendor, with how often the suggestion was right.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vendor_id
 * @property string $line_description_pattern
 * @property string $suggested_gl_account_id
 * @property string $suggested_vat_code_id
 * @property string|null $suggested_cost_center_id
 * @property int $historical_hit_count
 * @property int $correction_count
 * @property string $accuracy_rate
 * @property Carbon|null $last_used_at
 */
final class VendorCodingPredictionModel extends BaseEnterpriseModel
{
    protected $table = 'vendor_coding_prediction_models';

    protected function casts(): array
    {
        return [
            'historical_hit_count' => 'integer',
            'correction_count' => 'integer',
            'accuracy_rate' => 'decimal:2',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * hit_count / (hit_count + correction_count) * 100, 100 for a model never used.
     */
    public static function accuracyFor(int $hits, int $corrections): float
    {
        $total = $hits + $corrections;

        return $total === 0 ? 100.0 : round($hits / $total * 100, 2);
    }
}
