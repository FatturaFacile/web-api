<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A helpdesk-style service request for cleaning, repair, IT and the like, with a priority, an SLA due date, an assignee
 * and an optional link to a maintenance work order of the asset management module (referenced by id).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $category
 * @property string $priority
 * @property string $status
 * @property string $title
 * @property string|null $description
 * @property string|null $building_id
 * @property string|null $space_id
 * @property string|null $requester_id
 * @property string|null $assignee_id
 * @property string|null $asset_id
 * @property string|null $work_order_id
 * @property string|null $inspection_id
 * @property Carbon $due_at
 * @property Carbon|null $assigned_at
 * @property Carbon|null $responded_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property string|null $hold_reason
 * @property string|null $resolution_notes
 */
final class FacilityServiceRequest extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Categories = ['cleaning', 'repair', 'it', 'security', 'hvac', 'other'];

    /** @var list<string> */
    public const array Priorities = ['low', 'normal', 'high', 'urgent'];

    /** @var list<string> */
    public const array Statuses = ['new', 'assigned', 'in_progress', 'on_hold', 'resolved', 'closed', 'cancelled'];

    /** @var list<string> */
    public const array OpenStatuses = ['new', 'assigned', 'in_progress', 'on_hold'];

    /**
     * Allowed status changes: from => list of targets.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'new' => ['assigned', 'in_progress', 'cancelled'],
        'assigned' => ['assigned', 'in_progress', 'on_hold', 'cancelled'],
        'in_progress' => ['on_hold', 'resolved', 'cancelled'],
        'on_hold' => ['in_progress', 'cancelled'],
        'resolved' => ['closed', 'in_progress'],
        'closed' => [],
        'cancelled' => [],
    ];

    protected $table = 'fac_service_requests';

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'assigned_at' => 'datetime',
            'responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<FacilityBuilding, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(FacilityBuilding::class, 'building_id');
    }

    /**
     * @return BelongsTo<FacilitySpace, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'space_id');
    }

    /**
     * @return HasMany<FacilityRequestComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(FacilityRequestComment::class, 'service_request_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OpenStatuses, true);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::Transitions[$this->status] ?? [], true);
    }

    /**
     * An open request whose SLA due date has passed, or a request that was resolved after it.
     */
    public function isOverdue(?Carbon $at = null): bool
    {
        $reference = $this->resolved_at ?? ($this->isOpen() ? ($at ?? now()) : null);

        return $reference !== null && $reference->greaterThan($this->due_at);
    }
}
