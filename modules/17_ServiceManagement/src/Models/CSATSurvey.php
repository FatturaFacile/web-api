<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CSATSurvey extends BaseEnterpriseModel
{
    public const string StatusPending = 'pending';

    public const string StatusCompleted = 'completed';

    protected $table = 'esm_csat_surveys';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'responded_at' => 'datetime',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public static function sentimentFor(int $score): string
    {
        return match (true) {
            $score >= 4 => 'positive',
            $score === 3 => 'neutral',
            default => 'negative',
        };
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }
}
