<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * An entry of the compliance calendar: a legal or standard requirement that must be fulfilled by a due date, once or
 * on a recurrence. Completing a recurring obligation moves it to its next due date.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $template_key
 * @property string $framework
 * @property string $jurisdiction
 * @property string $title
 * @property string|null $description
 * @property string|null $legal_reference
 * @property string $recurrence
 * @property Carbon $due_date
 * @property int $reminder_days
 * @property string|null $owner_id
 * @property string $status
 * @property Carbon|null $last_completed_at
 * @property string|null $last_completed_by
 * @property string|null $evidence_document_id
 * @property string|null $notes
 */
final class GovObligation extends BaseEnterpriseModel
{
    public const array Frameworks = ['d_lgs_231', 'gdpr', 'nis2', 'fadp', 'iso_27001', 'other'];

    public const array Jurisdictions = ['IT', 'CH', 'EU', 'INT'];

    public const array Recurrences = ['once', 'monthly', 'quarterly', 'semiannual', 'annual'];

    public const array Statuses = ['pending', 'in_progress', 'completed', 'cancelled'];

    /** Statuses of an obligation that is still to be fulfilled. */
    public const array OpenStatuses = ['pending', 'in_progress'];

    /**
     * Allowed status changes through the transition endpoint: from => list of targets. Completion has its own action.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'pending' => ['in_progress', 'cancelled'],
        'in_progress' => ['pending', 'cancelled'],
        'completed' => [],
        'cancelled' => ['pending'],
    ];

    /**
     * Months in one period of a recurrence.
     *
     * @var array<string, int>
     */
    public const array PeriodMonths = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12];

    protected $table = 'gov_obligations';

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'reminder_days' => 'integer',
            'last_completed_at' => 'date',
        ];
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OpenStatuses, true) && $this->due_date->endOfDay()->isPast();
    }

    public function isDueSoon(): bool
    {
        return in_array($this->status, self::OpenStatuses, true)
            && ! $this->isOverdue()
            && $this->due_date->startOfDay()->lte(now()->startOfDay()->addDays($this->reminder_days));
    }
}
