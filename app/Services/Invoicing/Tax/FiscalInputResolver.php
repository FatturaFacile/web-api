<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Builds the calculator input from an invoice payload and the tenant fiscal preferences.
 *
 * Payload keys `fund`, `withholding`, `enasarco` and `stamp_duty_charged_to_customer`: when absent the tenant default
 * applies, an explicit null disables the option. Tenant default withholding is skipped under the flat-rate regime.
 */
class FiscalInputResolver
{
    public function __construct(private readonly EnasarcoService $enasarco) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function resolve(array $data, ?Tenant $tenant): FiscalInput
    {
        $settings = TenantFiscalSettings::fromTenant($tenant);

        $lines = array_map(
            fn (mixed $item): FiscalLine => FiscalLine::fromArray(is_array($item) ? $item : []),
            array_values((array) ($data['items'] ?? [])),
        );

        $fund = array_key_exists('fund', $data) ? $data['fund'] : $settings->defaultFund();
        $withholding = array_key_exists('withholding', $data)
            ? $data['withholding']
            : ($settings->isForfettario() ? null : $settings->defaultWithholding());

        $enasarco = array_key_exists('enasarco', $data)
            ? $data['enasarco']
            : ($settings->enasarcoEnabled() ? ['enabled' => true] : null);

        $chargedToCustomer = array_key_exists('stamp_duty_charged_to_customer', $data) && $data['stamp_duty_charged_to_customer'] !== null
            ? (bool) $data['stamp_duty_charged_to_customer']
            : $settings->stampDutyChargedToCustomer();

        $vatPayability = strtoupper((string) ($data['vat_payability'] ?? 'I'));

        return new FiscalInput(
            lines: $lines,
            regimeFiscale: $settings->regimeFiscale,
            splitPayment: (bool) ($data['split_payment'] ?? false) || $vatPayability === 'S',
            reverseCharge: (bool) ($data['reverse_charge'] ?? false),
            fund: $this->isEnabled($fund) ? FundSettings::fromArray($fund) : null,
            withholding: $this->isEnabled($withholding) ? WithholdingSettings::fromArray($withholding) : null,
            enasarco: $this->enasarcoSettings($enasarco, $data),
            stampDutyChargedToCustomer: $chargedToCustomer,
            vatPayability: $vatPayability,
        );
    }

    /**
     * An option is on when it is a non-empty object not marked `enabled: false` (an empty object would otherwise
     * produce a 0% cassa or ritenuta block in the XML).
     *
     * @phpstan-assert-if-true array<string, mixed> $option
     */
    private function isEnabled(mixed $option): bool
    {
        return is_array($option) && $option !== [] && ($option['enabled'] ?? true) !== false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function enasarcoSettings(mixed $enasarco, array $data): ?EnasarcoSettings
    {
        if (! is_array($enasarco) || ($enasarco['enabled'] ?? true) === false) {
            return null;
        }

        $year = Carbon::parse((string) ($data['date'] ?? now()->toDateString()))->year;
        $rate = $enasarco['rate'] ?? null;

        return $this->enasarco->settingsFor(
            (string) ($data['contact_id'] ?? ''),
            $year,
            is_int($rate) || is_float($rate) || (is_string($rate) && $rate !== '') ? $rate : null,
            isset($enasarco['reason']) ? strtoupper((string) $enasarco['reason']) : null,
        );
    }
}
