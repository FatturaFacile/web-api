<?php

declare(strict_types=1);

namespace Modules\Intrastat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Intrastat\Enums\DeclarationStatus;
use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Enums\Periodicity;

/**
 * One INTRA list (elenco riepilogativo): the frontespizio of a model INTRA-1 (sales) or INTRA-2 (purchases) for one
 * month or quarter, with its detail rows in sections 1 to 4.
 *
 * @property string $id
 * @property string $tenant_id
 * @property IntrastatFlow $flow
 * @property int $year
 * @property Periodicity $periodicity
 * @property int $period
 * @property int $reference_number
 * @property int $content_code
 * @property int $special_case_code
 * @property string|null $obligated_vat_number
 * @property string|null $delegate_vat_number
 * @property bool $includes_statistical_data
 * @property bool $has_annual_flows_above_20_million
 * @property DeclarationStatus $status
 * @property Carbon|null $validated_at
 * @property Carbon|null $submitted_at
 * @property string|null $protocol_number
 * @property array<string, mixed>|null $generation_report
 */
final class IntrastatDeclaration extends IntrastatRecord
{
    protected $table = 'intrastat_declarations';

    protected function casts(): array
    {
        return [
            'flow' => IntrastatFlow::class,
            'periodicity' => Periodicity::class,
            'status' => DeclarationStatus::class,
            'year' => 'integer',
            'period' => 'integer',
            'reference_number' => 'integer',
            'content_code' => 'integer',
            'special_case_code' => 'integer',
            'includes_statistical_data' => 'boolean',
            'has_annual_flows_above_20_million' => 'boolean',
            'validated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'last_exported_at' => 'datetime',
            'generation_report' => 'array',
        ];
    }

    /**
     * @return HasMany<IntrastatDeclarationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(IntrastatDeclarationLine::class, 'declaration_id')->orderBy('section')->orderBy('line_number');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * The party that sends the list: the delegate when there is one, the obliged party otherwise.
     */
    public function presenterVatNumber(): ?string
    {
        return $this->delegate_vat_number ?: $this->obligated_vat_number;
    }

    /**
     * Whether section 1 of a monthly sales list carries the statistical columns.
     */
    public function requiresStatisticalData(): bool
    {
        return $this->flow === IntrastatFlow::Purchases
            || ($this->periodicity === Periodicity::Monthly && ($this->includes_statistical_data || $this->has_annual_flows_above_20_million));
    }

    /**
     * First and last day of the period of the list.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodRange(): array
    {
        return self::rangeOf($this->year, $this->periodicity, $this->period);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function rangeOf(int $year, Periodicity $periodicity, int $period): array
    {
        $firstMonth = $periodicity === Periodicity::Monthly ? $period : (($period - 1) * 3) + 1;
        $start = Carbon::create($year, $firstMonth, 1)->startOfDay();
        $end = $periodicity === Periodicity::Monthly ? $start->copy()->endOfMonth() : $start->copy()->addMonths(2)->endOfMonth();

        return [$start, $end];
    }

    public function markEdited(): void
    {
        if ($this->status === DeclarationStatus::Validated) {
            $this->forceFill(['status' => DeclarationStatus::Draft, 'validated_at' => null])->save();
        }
    }
}
