<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Models\UsTaxExemptionCertificate;
use Modules\GeneralLedger\Models\UsTaxJurisdiction;
use Modules\GeneralLedger\Models\UsTaxNexus;
use Modules\GeneralLedger\Models\UsTaxRate;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * United States sales tax: collects tax only in states where the company has nexus on the sale date, and adds the rates
 * in force of every jurisdiction that covers the destination (state, county, city, special district by ZIP prefix).
 * A customer with a valid exemption certificate for the state (or for one of its jurisdictions) is not taxed there.
 */
final class UsSalesTaxService
{
    /**
     * @return array{state: string, date: string, has_nexus: bool, taxable_amount: string, shipping_amount: string, lines: list<array{jurisdiction_id: string, code: string, name: string, level: string, rate_percent: string, tax: string, exempt: bool}>, rate_percent: string, tax_total: string, exempt_tax: string, exemption_certificate_id: string|null, reason: string|null}
     */
    public function calculate(string $tenantId, string $state, string $date, string $taxableAmount, string $shippingAmount = '0', ?string $county = null, ?string $city = null, ?string $zip = null, ?string $customerId = null): array
    {
        $state = strtoupper($state);
        $day = Carbon::parse($date)->toDateString();
        $result = [
            'state' => $state,
            'date' => $day,
            'has_nexus' => $this->hasNexus($tenantId, $state, $day),
            'taxable_amount' => Decimal::round2($taxableAmount),
            'shipping_amount' => Decimal::round2($shippingAmount),
            'lines' => [],
            'rate_percent' => '0.0000',
            'tax_total' => '0.00',
            'exempt_tax' => '0.00',
            'exemption_certificate_id' => null,
            'reason' => null,
        ];

        if (! $result['has_nexus']) {
            $result['reason'] = 'no_nexus';

            return $result;
        }

        $certificates = $customerId !== null ? $this->validCertificates($tenantId, $customerId, $state, $day) : [];
        $total = '0';
        $exemptTotal = '0';
        $rate = '0';

        foreach ($this->jurisdictionsFor($tenantId, $state, $county, $city, $zip) as $jurisdiction) {
            $applicable = $this->rateOn($jurisdiction, $day);

            if ($applicable === null) {
                continue;
            }

            $base = $applicable->applies_to_shipping ? Decimal::add($taxableAmount, $shippingAmount) : $taxableAmount;
            $tax = Decimal::round2(Decimal::div(Decimal::mul($base, (string) $applicable->rate_percent), 100));
            $certificate = $this->certificateFor($certificates, (string) $jurisdiction->id);
            $rate = Decimal::add($rate, (string) $applicable->rate_percent);

            if ($certificate !== null) {
                $exemptTotal = Decimal::add($exemptTotal, $tax);
                $result['exemption_certificate_id'] = $certificate->id;
            } else {
                $total = Decimal::add($total, $tax);
            }

            $result['lines'][] = [
                'jurisdiction_id' => $jurisdiction->id,
                'code' => $jurisdiction->code,
                'name' => $jurisdiction->name,
                'level' => $jurisdiction->level,
                'rate_percent' => number_format((float) $applicable->rate_percent, 4, '.', ''),
                'tax' => $certificate !== null ? '0.00' : $tax,
                'exempt' => $certificate !== null,
            ];
        }

        $result['rate_percent'] = number_format((float) $rate, 4, '.', '');
        $result['tax_total'] = Decimal::round2($total);
        $result['exempt_tax'] = Decimal::round2($exemptTotal);
        $result['reason'] = match (true) {
            $result['lines'] === [] => 'no_rate',
            $result['exemption_certificate_id'] !== null && Decimal::isZero($result['tax_total']) => 'exempt',
            default => null,
        };

        return $result;
    }

    /**
     * The rates in force for a destination, whether or not the company has nexus there.
     *
     * @return array{state: string, date: string, has_nexus: bool, lines: list<array{jurisdiction_id: string, code: string, name: string, level: string, rate_percent: string}>, rate_percent: string}
     */
    public function lookup(string $tenantId, string $state, string $date, ?string $county = null, ?string $city = null, ?string $zip = null): array
    {
        $state = strtoupper($state);
        $day = Carbon::parse($date)->toDateString();
        $lines = [];
        $rate = '0';

        foreach ($this->jurisdictionsFor($tenantId, $state, $county, $city, $zip) as $jurisdiction) {
            $applicable = $this->rateOn($jurisdiction, $day);

            if ($applicable === null) {
                continue;
            }

            $rate = Decimal::add($rate, (string) $applicable->rate_percent);
            $lines[] = [
                'jurisdiction_id' => $jurisdiction->id,
                'code' => $jurisdiction->code,
                'name' => $jurisdiction->name,
                'level' => $jurisdiction->level,
                'rate_percent' => number_format((float) $applicable->rate_percent, 4, '.', ''),
            ];
        }

        return [
            'state' => $state,
            'date' => $day,
            'has_nexus' => $this->hasNexus($tenantId, $state, $day),
            'lines' => $lines,
            'rate_percent' => number_format((float) $rate, 4, '.', ''),
        ];
    }

    /**
     * Loads the state-level jurisdictions and rates of the seed set (see UsSalesTaxRateCatalog) for the tenant.
     * Idempotent: a state that already has its jurisdiction is skipped, and an existing rate is never overwritten.
     *
     * @param  list<string>|null  $states  restrict the preload to these states; null loads the whole seed set
     * @return array{created: int, skipped: int}
     */
    public function preload(string $tenantId, ?array $states = null): array
    {
        $created = 0;
        $skipped = 0;
        $wanted = $states !== null ? array_map('strtoupper', $states) : null;

        foreach (UsSalesTaxRateCatalog::states() as $code => $entry) {
            if ($wanted !== null && ! in_array($code, $wanted, true)) {
                continue;
            }

            if (UsTaxJurisdiction::query()->where('tenant_id', $tenantId)->where('code', "US-{$code}")->exists()) {
                $skipped++;

                continue;
            }

            $jurisdiction = UsTaxJurisdiction::create([
                'tenant_id' => $tenantId,
                'code' => "US-{$code}",
                'name' => $entry['name'],
                'state' => $code,
                'level' => 'state',
            ]);
            UsTaxRate::create([
                'tenant_id' => $tenantId,
                'jurisdiction_id' => $jurisdiction->id,
                'rate_percent' => $entry['rate'],
                'effective_from' => UsSalesTaxRateCatalog::EffectiveFrom,
                'applies_to_shipping' => false,
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return list<UsTaxExemptionCertificate>
     */
    private function validCertificates(string $tenantId, string $customerId, string $state, string $day): array
    {
        return UsTaxExemptionCertificate::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $customerId)
            ->where('state', $state)
            ->where('is_active', true)
            ->whereDate('issued_on', '<=', $day)
            ->where(fn ($query) => $query->whereNull('expires_on')->orWhereDate('expires_on', '>=', $day))
            ->orderBy('issued_on')
            ->get()
            ->all();
    }

    /**
     * The certificate that exempts a jurisdiction: one naming it, or one for the whole state.
     *
     * @param  list<UsTaxExemptionCertificate>  $certificates
     */
    private function certificateFor(array $certificates, string $jurisdictionId): ?UsTaxExemptionCertificate
    {
        foreach ($certificates as $certificate) {
            if ($certificate->jurisdiction_id === null || $certificate->jurisdiction_id === $jurisdictionId) {
                return $certificate;
            }
        }

        return null;
    }

    public function hasNexus(string $tenantId, string $state, string $date): bool
    {
        return UsTaxNexus::query()
            ->where('tenant_id', $tenantId)
            ->where('state', strtoupper($state))
            ->whereDate('established_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('ended_on')->orWhereDate('ended_on', '>=', $date))
            ->exists();
    }

    /**
     * @return Collection<int, UsTaxJurisdiction>
     */
    private function jurisdictionsFor(string $tenantId, string $state, ?string $county, ?string $city, ?string $zip): Collection
    {
        return UsTaxJurisdiction::query()
            ->where('tenant_id', $tenantId)
            ->where('state', $state)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->filter(fn (UsTaxJurisdiction $jurisdiction): bool => match ($jurisdiction->level) {
                'state' => true,
                'county' => $county !== null && strcasecmp((string) $jurisdiction->county, $county) === 0,
                'city' => $city !== null && strcasecmp((string) $jurisdiction->city, $city) === 0,
                default => $zip !== null && $jurisdiction->zip_prefix !== null && str_starts_with($zip, $jurisdiction->zip_prefix),
            })
            ->values();
    }

    private function rateOn(UsTaxJurisdiction $jurisdiction, string $day): ?UsTaxRate
    {
        return $jurisdiction->rates()
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->first();
    }
}
