<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SalesBoundedContext\Domain\Enums\NpsRespondentCategory;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $dossier_id
 * @property string|null $ddt_invoice_id
 * @property string $survey_code
 * @property string $trigger_event
 * @property int $nps_score
 * @property int $csat_score
 * @property int $ces_score
 * @property NpsRespondentCategory|null $nps_classification
 * @property bool $detractor_recovery_alert_triggered
 * @property string|null $recovery_action_owner
 * @property string $recovery_resolution_status
 * @property string $iso9001_clause_ref
 * @property string $iso9001_satisfaction_index
 * @property string|null $verbatim_feedback
 */
final class SbcPostDeliverySurvey extends BaseEnterpriseModel
{
    protected $table = 'sbc_post_delivery_surveys';

    protected function casts(): array
    {
        return [
            'nps_score' => 'integer',
            'csat_score' => 'integer',
            'ces_score' => 'integer',
            'nps_classification' => NpsRespondentCategory::class,
            'detractor_recovery_alert_triggered' => 'boolean',
            'iso9001_satisfaction_index' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SbcCustomerDossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(SbcCustomerDossier::class, 'dossier_id');
    }

    /**
     * @return BelongsTo<SbcDdtInvoice, $this>
     */
    public function ddtInvoice(): BelongsTo
    {
        return $this->belongsTo(SbcDdtInvoice::class, 'ddt_invoice_id');
    }
}
