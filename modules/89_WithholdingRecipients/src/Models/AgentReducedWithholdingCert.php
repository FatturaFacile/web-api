<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Annual declaration of an agent that employees or third parties are used continuously: the withholding is then computed on
 * 20% of the commission instead of 50%. Received by PEC or registered mail by 31 December.
 *
 * @property string $recipient_profile_id
 * @property int $fiscal_year
 * @property Carbon $received_on
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property string|null $pec_message_id
 * @property string|null $pec_archive_reference
 * @property string|null $document_path
 * @property string|null $notes
 */
final class AgentReducedWithholdingCert extends BaseEnterpriseModel
{
    protected $table = 'fiscal_agent_reduced_withholding_certs';

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'received_on' => 'date',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<RecipientProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(RecipientProfile::class, 'recipient_profile_id');
    }
}
