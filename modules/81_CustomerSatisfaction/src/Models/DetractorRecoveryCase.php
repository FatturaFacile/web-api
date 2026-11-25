<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Models;

use App\Models\Contact;
use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryPriority;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryStatus;

/**
 * Closed-loop recovery case opened for a detractor answer: owners, the 24 hour SLA, the CRM follow-up task and the churn risk move.
 *
 * @property string $survey_response_id
 * @property string $customer_id
 * @property string|null $assigned_account_manager_id
 * @property string|null $assigned_support_lead_id
 * @property string|null $crm_task_id
 * @property RecoveryPriority $priority
 * @property RecoveryStatus $status
 * @property Carbon $sla_due_at
 * @property Carbon|null $first_contacted_at
 * @property Carbon|null $resolved_at
 * @property bool $churn_risk_updated
 * @property string|null $previous_churn_risk
 * @property string|null $new_churn_risk
 * @property string|null $resolution_summary
 * @property string|null $root_cause_code
 */
final class DetractorRecoveryCase extends BaseEnterpriseModel
{
    protected $table = 'detractor_recovery_cases';

    protected function casts(): array
    {
        return [
            'priority' => RecoveryPriority::class,
            'status' => RecoveryStatus::class,
            'sla_due_at' => 'datetime',
            'first_contacted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'churn_risk_updated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SurveyResponse, $this>
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'survey_response_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_account_manager_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function supportLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_support_lead_id');
    }

    public function isSlaBreached(): bool
    {
        if ($this->first_contacted_at !== null) {
            return $this->first_contacted_at->greaterThan($this->sla_due_at);
        }

        return ! $this->status->isFinal() && $this->sla_due_at->isPast();
    }
}
