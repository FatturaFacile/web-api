<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PreservationDisposalProposalFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proposal to dispose of a closed package whose retention expired. Two-person rule: a different owner or administrator
 * approves it, then it is executed.
 *
 * @property string $id
 * @property string $package_id
 * @property string $status
 * @property string $proposed_by
 * @property ?Carbon $expiry_date
 * @property ?Carbon $proposed_at
 * @property ?Carbon $decided_at
 * @property ?Carbon $executed_at
 * @property ?string $decided_by
 * @property ?string $decision_note
 * @property ?string $executed_by
 * @property ?string $note
 */
#[UseFactory(PreservationDisposalProposalFactory::class)]
class PreservationDisposalProposal extends Model
{
    /** @use HasFactory<PreservationDisposalProposalFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const string Pending = 'pending';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    public const string Executed = 'executed';

    protected $fillable = ['tenant_id', 'package_id', 'status', 'expiry_date', 'note', 'proposed_by', 'proposed_at', 'decided_by', 'decided_at', 'decision_note', 'executed_by', 'executed_at'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date', 'proposed_at' => 'datetime', 'decided_at' => 'datetime', 'executed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<PreservationPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PreservationPackage::class, 'package_id');
    }
}
