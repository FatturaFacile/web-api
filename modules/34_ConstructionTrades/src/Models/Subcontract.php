<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The work entrusted to a subcontractor on a construction site, for an agreed amount. Certified work is recorded as
 * certifications and, when the contract belongs to a bid, flows into the site costs of that bid.
 *
 * @property string $id
 * @property string $construction_site_id
 * @property string $subcontractor_id
 * @property ?string $bid_id
 * @property string $contract_amount
 * @property string $status
 */
final class Subcontract extends TradesRecord
{
    /** @var list<string> */
    public const array Statuses = ['active', 'completed', 'cancelled'];

    protected $table = 'ct_subcontracts';

    protected function casts(): array
    {
        return ['contract_amount' => 'decimal:2', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class, 'subcontractor_id');
    }

    /**
     * @return HasMany<SubcontractCertification, $this>
     */
    public function certifications(): HasMany
    {
        return $this->hasMany(SubcontractCertification::class, 'subcontract_id')->orderBy('certified_on');
    }
}
