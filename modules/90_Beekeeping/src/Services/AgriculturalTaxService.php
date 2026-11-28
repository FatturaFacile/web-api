<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Models\TaxProfile;

/**
 * Agricultural VAT of apiculture products.
 *  - Italy, special agricultural regime (Art. 34 DPR 633/72): the invoice carries the percentage of compensation of the product and the
 *    producer does not deduct input VAT; the exemption regime (Art. 34, comma 6) charges no VAT below the turnover threshold; the ordinary
 *    regime uses the ordinary rates and deducts input VAT.
 *  - Switzerland: reduced rate (2.6%) for honey and edible bee products, standard rate (8.1%) for the rest, in direct and wholesale sales.
 */
final class AgriculturalTaxService
{
    /**
     * @return array<string, mixed>
     */
    public function profile(string $country): array
    {
        $profile = TaxProfile::query()->where('country', $country)->first();

        return [
            'country' => $country,
            'regime' => $profile->regime ?? ($country === 'CH' ? 'standard' : 'special_agricultural'),
            'compensation_overrides' => $profile->compensation_overrides ?? [],
            'annual_turnover' => $profile->annual_turnover ?? '0.00',
            'compensation_defaults' => (array) config('beekeeping.tax.IT.compensation'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveProfile(array $data): TaxProfile
    {
        $allowed = $data['country'] === 'CH' ? ['standard'] : ['special_agricultural', 'exemption', 'ordinary'];

        if (! in_array($data['regime'], $allowed, true)) {
            throw new BeekeepingException('The regime does not exist in this country.', 'tax_regime_invalid');
        }

        $profile = TaxProfile::query()->where('country', $data['country'])->first() ?? new TaxProfile(['country' => $data['country']]);
        $profile->fill([
            'regime' => $data['regime'],
            'compensation_overrides' => $data['compensation_overrides'] ?? null,
            'annual_turnover' => $data['annual_turnover'] ?? 0,
        ]);
        $profile->save();

        return $profile;
    }

    /**
     * @return array{country: string, regime: string, kind: string, channel: string, rate: float, net: float, vat: float, gross: float, input_vat_deductible: bool, notes: list<string>}
     */
    public function quote(string $country, string $kind, float $net, string $channel = 'direct'): array
    {
        $profile = $this->profile($country);
        $notes = [];
        $deductible = true;

        if ($country === 'CH') {
            $rate = in_array($kind, (array) config('beekeeping.tax.CH.reduced_kinds'), true) ? (float) config('beekeeping.tax.CH.reduced') : (float) config('beekeeping.tax.CH.standard');
            $notes[] = $rate === (float) config('beekeeping.tax.CH.reduced') ? 'ch_reduced_rate' : 'ch_standard_rate';
        } else {
            $regime = (string) $profile['regime'];
            $overrides = (array) $profile['compensation_overrides'];
            $ordinary = (float) config("beekeeping.tax.IT.ordinary.{$kind}", config('beekeeping.tax.IT.ordinary.non_food'));

            if ($kind === 'non_food' || $regime === 'ordinary') {
                $rate = $ordinary;
                $notes[] = 'it_ordinary_rate';
            } elseif ($regime === 'exemption') {
                $rate = 0.0;
                $deductible = false;
                $notes[] = 'it_exempt_art34_c6';

                if ((float) $profile['annual_turnover'] + $net > (float) config('beekeeping.tax.IT.exemption_threshold')) {
                    $notes[] = 'it_exemption_threshold_exceeded';
                }
            } else {
                $rate = (float) ($overrides[$kind] ?? config("beekeeping.tax.IT.compensation.{$kind}", $ordinary));
                $deductible = false;
                $notes[] = 'it_special_agricultural';
            }
        }

        $net = round($net, 2);
        $vat = round($net * $rate / 100, 2);

        return [
            'country' => $country,
            'regime' => (string) $profile['regime'],
            'kind' => $kind,
            'channel' => $channel,
            'rate' => $rate,
            'net' => $net,
            'vat' => $vat,
            'gross' => round($net + $vat, 2),
            'input_vat_deductible' => $deductible,
            'notes' => $notes,
        ];
    }
}
