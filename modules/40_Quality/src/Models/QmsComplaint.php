<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer complaint: received, investigated (root cause, non-conformity, CAPA), answered to the customer and closed.
 * A complaint that is not justified is rejected with the reason instead.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $customer_name
 * @property string|null $contact_id
 * @property Carbon $received_at
 * @property string $channel
 * @property string|null $reference
 * @property string|null $product_description
 * @property string $description
 * @property string $severity
 * @property string $status
 * @property string|null $owner_id
 * @property string|null $root_cause
 * @property string|null $nonconformity_id
 * @property string|null $capa_action_id
 * @property string|null $reply_text
 * @property Carbon|null $replied_at
 * @property string|null $replied_by
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 * @property string|null $closure_notes
 */
final class QmsComplaint extends BaseEnterpriseModel
{
    public const array Channels = ['email', 'phone', 'letter', 'web', 'in_person', 'other'];

    public const array Severities = ['minor', 'major', 'critical'];

    public const array OpenStatuses = ['received', 'investigating', 'replied'];

    protected $table = 'qms_complaints';

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'replied_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QmsNonconformity, $this>
     */
    public function nonconformity(): BelongsTo
    {
        return $this->belongsTo(QmsNonconformity::class, 'nonconformity_id');
    }

    /**
     * @return BelongsTo<QmsCapaAction, $this>
     */
    public function capaAction(): BelongsTo
    {
        return $this->belongsTo(QmsCapaAction::class, 'capa_action_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OpenStatuses, true);
    }
}
