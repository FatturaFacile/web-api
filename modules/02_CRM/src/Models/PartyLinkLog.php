<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Decision taken when a CRM account was linked to the party master: linked to an existing contact, contact created,
 * or left ambiguous for a person to resolve. Kept so that the consolidation is traceable and reviewable.
 *
 * @property string $id
 * @property string $account_id
 * @property string $outcome
 * @property string|null $matched_on
 * @property string|null $contact_id
 * @property list<string>|null $candidate_contact_ids
 * @property string|null $note
 * @property Carbon|null $updated_at
 */
final class PartyLinkLog extends BaseEnterpriseModel
{
    protected $table = 'crm_party_link_logs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'candidate_contact_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
