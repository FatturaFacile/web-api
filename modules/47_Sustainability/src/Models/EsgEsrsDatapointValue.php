<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The value of a data point for a reporting period with its review status. A data point that does not apply is marked
 * not_applicable and must carry the reason of the omission.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $datapoint_id
 * @property string $reporting_period_id
 * @property string $status
 * @property string|null $value_number
 * @property string|null $value_text
 * @property bool|null $value_boolean
 * @property string $source
 * @property string|null $omission_reason
 * @property string|null $notes
 * @property string|null $updated_by
 */
final class EsgEsrsDatapointValue extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Reviewed = 'reviewed';

    public const string Approved = 'approved';

    public const string NotApplicable = 'not_applicable';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::Reviewed, self::Approved, self::NotApplicable];

    /** @var list<string> */
    public const array Sources = ['manual', 'inventory'];

    protected $table = 'esg_esrs_datapoint_values';

    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:4',
            'value_boolean' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<EsgEsrsDatapoint, $this>
     */
    public function datapoint(): BelongsTo
    {
        return $this->belongsTo(EsgEsrsDatapoint::class, 'datapoint_id');
    }

    public function hasValue(): bool
    {
        return $this->value_number !== null || ($this->value_text !== null && $this->value_text !== '') || $this->value_boolean !== null;
    }
}
