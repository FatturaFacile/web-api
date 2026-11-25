<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A stored consolidated financial statement package of a run, in one reporting standard.
 *
 * @property array<string, mixed> $payload
 * @property Carbon $generated_at
 */
final class ConsolidationPackage extends BaseEnterpriseModel
{
    protected $table = 'ic_consolidation_packages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
