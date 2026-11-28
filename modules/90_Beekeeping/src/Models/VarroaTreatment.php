<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Beekeeping\Domain\ValueObjects\WithdrawalWindow;

/**
 * A veterinary anti-varroa treatment of an apiary or of some of its hives, with its withdrawal period.
 *
 * @property string $apiary_id
 * @property bool $whole_apiary
 * @property list<string>|null $hive_ids
 * @property Carbon $treatment_date
 * @property Carbon|null $treatment_end_date
 * @property string $product
 * @property string $active_substance
 * @property string|null $commercial_name
 * @property string|null $drug_batch
 * @property string|null $prescription_ref
 * @property string|null $veterinarian
 * @property string|null $dosage
 * @property string|null $application_method
 * @property int $withdrawal_days
 * @property Carbon $withdrawal_end_date
 * @property string|null $notes
 */
final class VarroaTreatment extends BaseEnterpriseModel
{
    protected $table = 'bee_varroa_treatments';

    protected static function booted(): void
    {
        // The end of the withdrawal is always derived from the treatment date and the days.
        self::saving(static function (self $treatment): void {
            $treatment->withdrawal_end_date = Carbon::parse((new WithdrawalWindow(CarbonImmutable::parse($treatment->treatment_date), (int) $treatment->withdrawal_days))->end->toDateString());
        });
    }

    protected function casts(): array
    {
        return [
            'whole_apiary' => 'boolean',
            'hive_ids' => 'array',
            'treatment_date' => 'date',
            'treatment_end_date' => 'date',
            'withdrawal_days' => 'integer',
            'withdrawal_end_date' => 'date',
        ];
    }

    public function window(): WithdrawalWindow
    {
        return new WithdrawalWindow(CarbonImmutable::parse($this->treatment_date), (int) $this->withdrawal_days);
    }

    /**
     * @return BelongsTo<Apiary, $this>
     */
    public function apiary(): BelongsTo
    {
        return $this->belongsTo(Apiary::class, 'apiary_id');
    }
}
