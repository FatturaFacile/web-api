<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use App\Enums\Privacy\RopaLegalBasis;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Record of processing activities (GDPR art. 30, nLPD art. 12).
 *
 * @property string $id
 * @property string $activity_code
 * @property RopaLegalBasis $legal_basis
 * @property list<array{country_code: string, adequacy_decision: bool, safeguard_mechanism: string}>|null $cross_border_transfers
 * @property list<string>|null $data_subject_categories
 * @property list<string>|null $personal_data_categories
 * @property list<string>|null $recipient_categories
 * @property list<string>|null $technical_organizational_measures
 * @property int $retention_period_months
 * @property string|null $retention_legal_reference
 * @property bool $is_active
 */
final class ProcessingActivityRecordRopa extends Model
{
    use BelongsToTenant, HasUuidV7, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected $table = 'privacy_ropa_records';

    protected function casts(): array
    {
        return [
            'legal_basis' => RopaLegalBasis::class,
            'data_subject_categories' => 'array',
            'personal_data_categories' => 'array',
            'recipient_categories' => 'array',
            'cross_border_transfers' => 'array',
            'technical_organizational_measures' => 'array',
            'retention_period_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
