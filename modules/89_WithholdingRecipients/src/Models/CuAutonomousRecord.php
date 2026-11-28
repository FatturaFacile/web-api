<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Annual certification of a recipient: the Certificazione Unica record (lavoro autonomo) of an Italian payee, or the AVS /
 * source tax statement of a Swiss one.
 *
 * @property string $recipient_profile_id
 * @property int $tax_year
 * @property string $return_type
 * @property string|null $causal
 * @property string $currency
 * @property string $gross_total
 * @property string $non_taxable_total
 * @property string|null $non_taxable_code
 * @property string $taxable_total
 * @property string $withholding_total
 * @property string $inps_worker_total
 * @property string $inps_company_total
 * @property string $enasarco_total
 * @property string $avs_total
 * @property string $source_tax_total
 * @property int $settlement_count
 * @property string $status
 * @property Carbon $generated_at
 * @property array<string, mixed>|null $payload
 * @property string|null $created_by
 * @property string|null $updated_by
 */
final class CuAutonomousRecord extends BaseEnterpriseModel
{
    public const string TypeCu = 'CU';

    public const string TypeAvsQst = 'AVS_QST';

    protected $table = 'fiscal_cu_autonomous_records';

    protected function casts(): array
    {
        return [
            'tax_year' => 'integer',
            'gross_total' => 'decimal:2',
            'non_taxable_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'withholding_total' => 'decimal:2',
            'inps_worker_total' => 'decimal:2',
            'inps_company_total' => 'decimal:2',
            'enasarco_total' => 'decimal:2',
            'avs_total' => 'decimal:2',
            'source_tax_total' => 'decimal:2',
            'settlement_count' => 'integer',
            'generated_at' => 'datetime',
            'payload' => 'array',
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
