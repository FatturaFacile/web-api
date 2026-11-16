<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Models;

use App\Models\Contact;
use App\Models\LegalCase;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fee proposal sent to a prospective client; accepting it opens the matter.
 *
 * @property string $id
 * @property ?string $tenant_id
 */
final class PracticeFeeProposal extends Model
{
    use BelongsToTenant, HasUuidV7;

    /** @var list<string> */
    public const array Statuses = ['draft', 'sent', 'accepted', 'declined', 'expired'];

    /** @var list<string> */
    public const array FeeTypes = ['hourly', 'fixed'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'practice_fee_proposals';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['valid_until' => 'immutable_date', 'hourly_rate' => 'decimal:2', 'estimated_hours' => 'decimal:2', 'fixed_fee' => 'decimal:2', 'estimated_total' => 'decimal:2', 'sent_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_id');
    }

    /**
     * @return BelongsTo<LegalCase, $this>
     */
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'legal_case_id');
    }
}
