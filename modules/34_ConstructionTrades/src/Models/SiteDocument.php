<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A safety or compliance document of a site or of one of its subcontractors, with the date it expires. The file lives
 * in the Documents module and is referenced by id.
 *
 * @property string $id
 * @property string $construction_site_id
 * @property ?string $subcontractor_id
 * @property ?string $dms_document_id
 * @property string $kind
 * @property ?Carbon $expires_on
 */
final class SiteDocument extends TradesRecord
{
    /** @var list<string> */
    public const array Kinds = ['safety_plan', 'risk_assessment', 'insurance', 'compliance_certificate', 'training_certificate', 'permit', 'other'];

    protected $table = 'ct_site_documents';

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date'];
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class, 'subcontractor_id');
    }
}
