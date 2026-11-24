<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The label of a reason code in one locale.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $reason_code_id
 * @property string $locale
 * @property string $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MfgReasonCode|null $reasonCode
 */
final class MfgReasonCodeLabel extends BaseEnterpriseModel
{
    protected $table = 'mfg_reason_code_labels';

    /**
     * @return BelongsTo<MfgReasonCode, $this>
     */
    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(MfgReasonCode::class, 'reason_code_id');
    }
}
