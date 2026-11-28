<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * HACCP recall report generated from a delivery, laboratory result, vat batch or wheel.
 *
 * @property string $source_type
 * @property string $source_id
 * @property string $reason
 * @property bool $applied
 * @property Carbon $generated_at
 * @property array<string,mixed>|null $payload
 */
final class RecallReport extends BaseEnterpriseModel
{
    protected $table = 'dairy_recall_reports';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'applied' => 'boolean',
            'generated_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
