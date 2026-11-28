<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\WithholdingRecipients\Domain\Enums\Jurisdiction;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;
use Modules\WithholdingRecipients\Models\CuAutonomousRecord;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

/**
 * Annual aggregation of the posted settlements per recipient and tax year: the Certificazione Unica record (and the data
 * of the Modello 770) of the Italian payees, with the ministerial causal and the code of the sums not subject to
 * withholding, and the AVS / source tax statement of the Swiss ones. Regenerating a year replaces its records.
 */
final class AnnualReturnService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @return Collection<int, CuAutonomousRecord>
     */
    public function generate(int $taxYear, ?string $recipientProfileId, ?string $userId): Collection
    {
        return DB::transaction(function () use ($taxYear, $recipientProfileId, $userId): Collection {
            $settlements = RecipientSettlement::query()
                ->where('tax_year', $taxYear)
                ->where('status', 'posted')
                ->when($recipientProfileId, static fn ($q, string $id) => $q->where('recipient_profile_id', $id))
                ->orderBy('payment_date')
                ->get()
                ->groupBy('recipient_profile_id');

            $records = new Collection;

            foreach ($settlements as $profileId => $rows) {
                $profile = RecipientProfile::query()->find($profileId);

                if ($profile !== null) {
                    $records->push($this->upsert($profile, $taxYear, $rows, $userId));
                }
            }

            return $records;
        });
    }

    /**
     * @param  Collection<int, RecipientSettlement>  $rows
     */
    private function upsert(RecipientProfile $profile, int $taxYear, Collection $rows, ?string $userId): CuAutonomousRecord
    {
        $italian = $profile->regimeEnum()->jurisdiction() === Jurisdiction::Italy;
        $sum = static fn (string $column): Money => Money::of($rows->sum(static fn (RecipientSettlement $s): float => (float) $s->{$column}));
        $type = $italian ? CuAutonomousRecord::TypeCu : CuAutonomousRecord::TypeAvsQst;
        $currency = $profile->regimeEnum()->currency();
        $codes = $rows->pluck('non_taxable_code')->filter()->unique()->values();

        $record = CuAutonomousRecord::query()->firstOrNew([
            'tenant_id' => (string) $this->tenantContext->getTenantId(),
            'recipient_profile_id' => $profile->id,
            'tax_year' => $taxYear,
            'return_type' => $type,
        ]);

        $record->fill([
            'causal' => $italian ? ($profile->cu_causal ?? $rows->pluck('cu_causal')->filter()->first()) : null,
            'currency' => $currency,
            'gross_total' => $sum('gross_amount')->toDecimal(),
            'non_taxable_total' => $sum('non_taxable_amount')->toDecimal(),
            'non_taxable_code' => $codes->first(),
            'taxable_total' => $sum('taxable_base')->toDecimal(),
            'withholding_total' => $sum('irpef_withholding')->toDecimal(),
            'inps_worker_total' => $sum('inps_worker')->toDecimal(),
            'inps_company_total' => $sum('inps_company')->toDecimal(),
            'enasarco_total' => $sum('enasarco_agent')->toDecimal(),
            'avs_total' => $sum('avs_employee')->plus($sum('avs_employer'))->toDecimal(),
            'source_tax_total' => $sum('source_tax')->toDecimal(),
            'settlement_count' => $rows->count(),
            'status' => 'generated',
            'generated_at' => CarbonImmutable::now(),
            'updated_by' => $userId,
            'payload' => [
                'recipient' => ['name' => $profile->display_name, 'tax_code' => $profile->tax_code, 'vat_number' => $profile->vat_number, 'avs_number' => $profile->avs_number],
                'lines' => $rows->map(static fn (RecipientSettlement $s): array => [
                    'settlement_id' => $s->id,
                    'payment_date' => $s->payment_date->toDateString(),
                    'gross' => $s->gross_amount,
                    'non_taxable' => $s->non_taxable_amount,
                    'withholding' => $s->irpef_withholding,
                    'source_tax' => $s->source_tax,
                ])->all(),
            ],
        ]);

        if (! $record->exists) {
            $record->created_by = $userId;
        }

        $record->save();

        return $record;
    }

    /**
     * Flat CSV of the records of a year (one line per recipient).
     */
    public function csv(int $taxYear): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['tax_year', 'return_type', 'recipient', 'tax_code', 'avs_number', 'causal', 'currency', 'gross', 'non_taxable', 'non_taxable_code', 'taxable', 'withholding', 'inps_worker', 'inps_company', 'enasarco', 'avs', 'source_tax'], ';', '"', '');

        foreach (CuAutonomousRecord::query()->with('profile')->where('tax_year', $taxYear)->orderBy('return_type')->get() as $record) {
            fputcsv($handle, [
                $record->tax_year, $record->return_type, $record->profile?->display_name, $record->profile?->tax_code, $record->profile?->avs_number, $record->causal, $record->currency,
                $record->gross_total, $record->non_taxable_total, $record->non_taxable_code, $record->taxable_total, $record->withholding_total,
                $record->inps_worker_total, $record->inps_company_total, $record->enasarco_total, $record->avs_total, $record->source_tax_total,
            ], ';', '"', '');
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }
}
