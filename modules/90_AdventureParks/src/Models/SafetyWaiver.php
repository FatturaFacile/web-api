<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Signed liability waiver of a ticket: the adult signer (who answers for the minors), the signature image, the legal text version
 * accepted, the evidence of the signing (IP, user agent, time, language) and the safety briefing given by an instructor.
 *
 * @property string $ticket_id
 * @property string $signer_full_name
 * @property string|null $signer_fiscal_code
 * @property string|null $signer_phone
 * @property string|null $signer_email
 * @property Carbon|null $signer_birth_date
 * @property string|null $signer_height_cm
 * @property string|null $signer_weight_kg
 * @property string $signature_data
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $locale
 * @property string $waiver_version_id
 * @property bool $accepted_rules
 * @property Carbon $signed_at
 * @property Carbon|null $briefing_completed_at
 * @property string|null $briefing_instructor_id
 */
final class SafetyWaiver extends BaseEnterpriseModel
{
    protected $table = 'adv_safety_waivers';

    protected function casts(): array
    {
        return [
            'signer_birth_date' => 'date',
            'signer_height_cm' => 'decimal:1',
            'signer_weight_kg' => 'decimal:1',
            'accepted_rules' => 'boolean',
            'signed_at' => 'datetime',
            'briefing_completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function completeBriefing(string $instructorId, CarbonInterface $at): void
    {
        $this->briefing_completed_at = $at;
        $this->briefing_instructor_id = $instructorId;
    }
}
