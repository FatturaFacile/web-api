<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A visitor of a ticket: the adult who signed the waiver or a minor covered by it.
 *
 * @property string $ticket_id
 * @property string $full_name
 * @property bool $is_minor
 * @property bool $is_signer
 * @property Carbon|null $birth_date
 * @property string|null $height_cm
 * @property string|null $weight_kg
 * @property string|null $size
 * @property string|null $fiscal_code
 * @property string|null $phone
 * @property string|null $email
 */
final class TicketParticipant extends BaseEnterpriseModel
{
    protected $table = 'adv_ticket_participants';

    protected function casts(): array
    {
        return [
            'is_minor' => 'boolean',
            'is_signer' => 'boolean',
            'birth_date' => 'date',
            'height_cm' => 'decimal:1',
            'weight_kg' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    /**
     * @return HasMany<TicketPpeAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TicketPpeAssignment::class, 'participant_id');
    }
}
