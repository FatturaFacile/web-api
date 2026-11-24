<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Procurement\Enums\MatchResolutionAction;

/**
 * Audit history of the exception resolution of a blocked invoice: who did what, why, and the status change.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $run_id
 * @property string $passive_invoice_id
 * @property MatchResolutionAction $action
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $reason
 * @property string|null $actor_id
 * @property array<string, mixed>|null $context
 * @property Carbon $resolved_at
 */
final class InvoiceMatchResolution extends BaseEnterpriseModel
{
    protected $table = 'invoice_match_resolutions';

    protected function casts(): array
    {
        return [
            'action' => MatchResolutionAction::class,
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InvoiceMatchingRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(InvoiceMatchingRun::class, 'run_id');
    }
}
