<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One GDPR consent event (granted or withdrawn) of a contact or lead, for a channel and purpose. Records are
 * append-only: the latest record per subject, channel and purpose is the effective state, and the history is the
 * evidence required by art. 7 GDPR.
 *
 * @property string $id
 * @property string|null $contact_id
 * @property string|null $lead_id
 * @property string $channel
 * @property string $purpose
 * @property string $status
 * @property string $source
 * @property string|null $evidence
 * @property string|null $recorded_by_user_id
 * @property Carbon|null $captured_at
 * @property Carbon|null $created_at
 */
final class Consent extends BaseEnterpriseModel
{
    public const array Channels = ['email', 'sms', 'phone', 'post'];

    public const array Purposes = ['marketing', 'newsletter', 'profiling'];

    public const array Sources = ['web_form', 'import', 'phone', 'in_person', 'api', 'unsubscribe_link', 'lead_conversion', 'contact_merge'];

    public const string Granted = 'granted';

    public const string Withdrawn = 'withdrawn';

    protected $table = 'crm_consents';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }
}
