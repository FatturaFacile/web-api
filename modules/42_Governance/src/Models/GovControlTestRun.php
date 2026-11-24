<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One test of a control with its result and the evidence documents of the DMS that support it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $control_id
 * @property Carbon $tested_on
 * @property string|null $tester_id
 * @property string $result
 * @property int|null $sample_size
 * @property int|null $exceptions_found
 * @property string|null $notes
 * @property list<string>|null $evidence_document_ids
 * @property string|null $finding_id
 */
final class GovControlTestRun extends BaseEnterpriseModel
{
    public const array Results = ['pass', 'partial', 'fail'];

    protected $table = 'gov_control_test_runs';

    protected function casts(): array
    {
        return [
            'tested_on' => 'date',
            'sample_size' => 'integer',
            'exceptions_found' => 'integer',
            'evidence_document_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<GovControl, $this>
     */
    public function control(): BelongsTo
    {
        return $this->belongsTo(GovControl::class, 'control_id');
    }
}
