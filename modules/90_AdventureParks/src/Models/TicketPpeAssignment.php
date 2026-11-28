<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A PPE item handed to a participant of a ticket, until it is returned.
 *
 * @property string $ticket_id
 * @property string $participant_id
 * @property string $ppe_asset_id
 * @property Carbon $assigned_at
 * @property Carbon|null $returned_at
 * @property string|null $usage_hours
 */
final class TicketPpeAssignment extends BaseEnterpriseModel
{
    protected $table = 'adv_ticket_ppe_assignments';

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'returned_at' => 'datetime',
            'usage_hours' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PpeAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(PpeAsset::class, 'ppe_asset_id');
    }

    /**
     * @return BelongsTo<TicketParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(TicketParticipant::class, 'participant_id');
    }

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }

    public function close(CarbonInterface $at, float $usageHours): void
    {
        $this->returned_at = $at;
        $this->usage_hours = number_format($usageHours, 2, '.', '');
    }
}
