<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\Frontier;

use App\Services\Tenancy\TenantContext;
use Modules\Payroll\Exports\CsvBuilder;
use Modules\Payroll\Exports\GeneratedFile;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollFrontierYear;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Services\LegalParameterResolver;
use Modules\Payroll\Services\LegalParameterSet;
use Modules\Payroll\Services\Withholding\WithholdingTaxAmounts;

/**
 * Classifies the workers resident in Italy under the Switzerland-Italy agreement. The rules are the parameters of the
 * year; the employer's own assessment stays on the profile and is only checked. The engine is not touched: the rate comes
 * from the tariff the employer applies, and the reduced Swiss share of the new regime is not multiplied again here, because
 * nothing read so far says whether the published R, S, T and U tables already contain it.
 */
final class FrontierWorkerService
{
    public function __construct(
        private readonly LegalParameterResolver $parameters,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{year: int, parameters_year: int, warnings: list<string>, workers: list<array<string, mixed>>}
     */
    public function overview(int $year): array
    {
        $set = $this->parameters->resolve('frontier_italy', $year);
        $profiles = PayrollEmployeeProfile::query()->with('employee')->where('residence_country', 'IT')->get();
        $nights = PayrollFrontierYear::query()->where('year', $year)->pluck('nights_away', 'employee_id');

        return [
            'year' => $year,
            'parameters_year' => $set->year,
            'warnings' => $set->warnings,
            'workers' => $profiles->map(fn (PayrollEmployeeProfile $profile): array => $this->assess($profile, (int) $nights->get($profile->employee_id, 0), $set))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assess(PayrollEmployeeProfile $profile, int $nightsAway, LegalParameterSet $set): array
    {
        $suggested = $this->suggestedRegime($profile, $nightsAway, $set);
        $stored = $profile->frontier_regime === null ? null : FrontierRegime::from($profile->frontier_regime);
        $effective = $stored ?? $suggested;
        $issues = [];

        if ($stored !== null && $stored !== $suggested) {
            $issues[] = "Regime indicato {$stored->value}, dai dati risulta {$suggested->value}: verificare.";
        }

        if ($nightsAway > (int) $set->required('maximum_nights_away_per_year') && $profile->frontier_returns_daily) {
            $issues[] = "Oltre {$set->required('maximum_nights_away_per_year')} notti fuori casa: lo statuto di frontaliere si perde per l'anno (FAQ 2.7).";
        }

        $tariff = strtoupper(substr((string) $profile->withholding_tax_code, 0, 1));
        $expected = match ($effective) {
            FrontierRegime::Old => $set->required('old_regime_tariffs'),
            FrontierRegime::New => $set->required('new_regime_tariffs'),
            FrontierRegime::NotCovered => null,
        };

        if ($expected !== null && $tariff !== '' && ! in_array($tariff, $expected, true)) {
            $issues[] = "Tariffa {$tariff} non prevista per il regime {$effective->value} (".implode(', ', $expected).').';
        }

        if ($profile->frontier_status_valid_from === null) {
            $issues[] = 'Manca la data "validità dal" dello statuto: determina vecchio o nuovo frontaliere.';
        }

        if ($profile->frontier_commune_in_zone === null) {
            $issues[] = "Comune di residenza non confrontato con l'elenco ufficiale dei comuni entro 20 km.";
        }

        $exchange = $effective !== FrontierRegime::Old;
        $missing = $exchange ? $this->missingExchangeData($profile) : [];

        return [
            'employee_id' => (string) $profile->employee_id,
            'employee_name' => (string) $profile->employee?->full_name,
            'work_canton' => $profile->work_canton,
            'regime' => $effective->value,
            'suggested_regime' => $suggested->value,
            'tariff_letter' => $tariff === '' ? null : $tariff,
            'nights_away' => $nightsAway,
            'exchange_of_information' => $exchange,
            'missing_exchange_data' => $missing,
            'issues' => $issues,
        ];
    }

    /**
     * The data of art. 7 of the agreement for the workers it concerns, from the closed runs of the year. It feeds the
     * employer's declaration: the file layout is the one of this application, not an official one.
     */
    public function exchangeFile(int $year): GeneratedFile
    {
        $set = $this->parameters->resolve('frontier_italy', $year);
        $profiles = PayrollEmployeeProfile::query()->with('employee')->where('residence_country', 'IT')->get();
        $nights = PayrollFrontierYear::query()->where('year', $year)->pluck('nights_away', 'employee_id');
        $closedRunIds = PayrollRun::query()->where('year', $year)->where('status', PayrollRun::StatusFinal)->pluck('id');
        $tenant = $this->tenantContext->getTenant();
        $csv = new CsvBuilder;
        $csv->row('Cognome', 'Nome', 'Data di nascita', 'Luogo di nascita', 'Indirizzo', 'Codice fiscale italiano', 'Salario lordo', 'Contributi sociali obbligatori', 'Imposta alla fonte', 'Datore di lavoro', 'IDE datore di lavoro');

        foreach ($profiles as $profile) {
            if (! $this->assess($profile, (int) $nights->get($profile->employee_id, 0), $set)['exchange_of_information']) {
                continue;
            }

            $payslips = Payslip::query()->whereIn('payroll_run_id', $closedRunIds)->where('employee_id', $profile->employee_id)->with('lines')->get();

            if ($payslips->isEmpty()) {
                continue;
            }

            $amounts = WithholdingTaxAmounts::of($payslips);
            $csv->row(
                $profile->employee?->last_name,
                $profile->employee?->first_name,
                $profile->employee?->birth_date,
                $profile->birth_place,
                trim("{$profile->street} {$profile->house_number}, {$profile->postal_code} {$profile->city}"),
                $profile->italian_tax_code,
                $amounts['gross'],
                $amounts['social_contributions'],
                $amounts['tax'],
                $tenant?->legal_name,
                $tenant?->vat_number ?? $tenant?->fiscal_code,
            );
        }

        return new GeneratedFile(
            "Frontalieri_scambio_informazioni_{$year}.csv",
            $csv->encoded(),
            "Dati dell'art. 7 dell'accordo per il datore di lavoro. Il tracciato è di questa applicazione e non è un formato ufficiale: i dati vanno immessi in IFonte o trasmessi da un software certificato.",
        );
    }

    private function suggestedRegime(PayrollEmployeeProfile $profile, int $nightsAway, LegalParameterSet $set): FrontierRegime
    {
        $isInRegion = in_array(strtoupper((string) $profile->work_canton), $set->required('border_cantons'), true);
        $returnsHome = $profile->frontier_returns_daily && $nightsAway <= (int) $set->required('maximum_nights_away_per_year');

        if (strtoupper((string) $profile->residence_country) !== 'IT' || ! $isInRegion || $profile->frontier_commune_in_zone === false || ! $returnsHome) {
            return FrontierRegime::NotCovered;
        }

        $validFrom = $profile->frontier_status_valid_from?->format('Y-m-d');

        return $validFrom !== null && $validFrom <= $set->required('transitional_to') ? FrontierRegime::Old : FrontierRegime::New;
    }

    /**
     * @return list<string>
     */
    private function missingExchangeData(PayrollEmployeeProfile $profile): array
    {
        return array_values(array_filter([
            $profile->birth_place === null ? 'birth_place' : null,
            $profile->italian_tax_code === null ? 'italian_tax_code' : null,
            $profile->street === null ? 'street' : null,
        ]));
    }
}
