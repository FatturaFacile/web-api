<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the quarterly Enasarco statement of the tenant as a principal: the commissions of an agent in the quarter,
 * the part of them still below the annual ceiling, the agent's share withheld on its invoices and the principal's share.
 */
final class EnasarcoPrincipalStatement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'year', 'quarter', 'contact_id', 'agent_type', 'documents', 'commissions', 'subject_before', 'subject_base',
        'annual_ceiling', 'rate_percent', 'agent_share_percent', 'agent_withheld', 'principal_amount', 'total_due',
        'journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'documents' => 'integer',
            'commissions' => 'decimal:2',
            'subject_before' => 'decimal:2',
            'subject_base' => 'decimal:2',
            'annual_ceiling' => 'decimal:2',
            'rate_percent' => 'decimal:2',
            'agent_share_percent' => 'decimal:2',
            'agent_withheld' => 'decimal:2',
            'principal_amount' => 'decimal:2',
            'total_due' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
