<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

use InvalidArgumentException;
use Modules\HumanResources\Services\Payroll\Rappen;

/**
 * Resolved, typed view of a `hr_ch_rate_sets.rates` document. Amounts are integer Rappen, rates are parts per
 * million. The calculator only ever reads rates from this object.
 *
 * Expected document shape (percentages and CHF amounts as decimal strings):
 *
 * ```
 * ahv_iv_eo: {employee, employer}
 * ahv_admin_cost: {employer}                      percentage of the AHV salary
 * ahv_contribution_start_age: int                 contributions start on 1 January after this birthday
 * reference_age: int                              AHV reference (retirement) age
 * ahv_retiree_allowance_monthly: CHF              exemption for employees above the reference age
 * alv: {employee, employer, ceiling_annual, solidarity_employee, solidarity_employer}
 * bvg: {entry_threshold, coordination_deduction, upper_limit, min_coordinated_salary, employer_share,
 *       age_credits: [{from_age, to_age, rate}]}
 * uvg: {max_insured_annual, classes: {<code>: {bu, nbu}}}
 * ktg: {employee, employer}
 * fak: {default, cantons: {<canton>: rate}}
 * ```
 */
final readonly class RateSet
{
    /**
     * @param  list<array{from_age: int, to_age: int, rate_ppm: int}>  $bvgAgeCredits
     * @param  array<string, array{bu_ppm: int, nbu_ppm: int}>  $uvgClasses
     * @param  array<string, int>  $fakCantonRatesPpm
     */
    public function __construct(
        public string $id,
        public string $code,
        public int $year,
        public int $ahvEmployeePpm,
        public int $ahvEmployerPpm,
        public int $ahvAdminCostPpm,
        public int $ahvContributionStartAge,
        public int $referenceAge,
        public int $retireeAllowanceMonthlyRappen,
        public int $alvEmployeePpm,
        public int $alvEmployerPpm,
        public int $alvCeilingAnnualRappen,
        public int $alvSolidarityEmployeePpm,
        public int $alvSolidarityEmployerPpm,
        public int $bvgEntryThresholdRappen,
        public int $bvgCoordinationDeductionRappen,
        public int $bvgUpperLimitRappen,
        public int $bvgMinCoordinatedSalaryRappen,
        public int $bvgEmployerSharePpm,
        public array $bvgAgeCredits,
        public int $uvgMaxInsuredAnnualRappen,
        public array $uvgClasses,
        public int $ktgEmployeePpm,
        public int $ktgEmployerPpm,
        public int $fakDefaultPpm,
        public array $fakCantonRatesPpm,
    ) {}

    /**
     * @param  array<string, mixed>  $rates
     *
     * @throws InvalidArgumentException when a required value is missing or malformed
     */
    public static function fromArray(array $rates, string $id = '', string $code = '', int $year = 0): self
    {
        $ageCredits = [];
        $rawAgeCredits = self::value($rates, 'bvg.age_credits');

        if (! is_array($rawAgeCredits) || $rawAgeCredits === []) {
            throw new InvalidArgumentException('rates.bvg.age_credits must be a non-empty list.');
        }

        foreach (array_values($rawAgeCredits) as $index => $band) {
            if (! is_array($band)) {
                throw new InvalidArgumentException("rates.bvg.age_credits.{$index} must be an object.");
            }

            $ageCredits[] = [
                'from_age' => self::integer($band, 'from_age', "bvg.age_credits.{$index}."),
                'to_age' => self::integer($band, 'to_age', "bvg.age_credits.{$index}."),
                'rate_ppm' => self::percent($band, 'rate', "bvg.age_credits.{$index}."),
            ];
        }

        $uvgClasses = [];
        $rawClasses = self::value($rates, 'uvg.classes');

        if (! is_array($rawClasses)) {
            throw new InvalidArgumentException('rates.uvg.classes must be an object keyed by class code.');
        }

        foreach ($rawClasses as $classCode => $classRates) {
            if (! is_array($classRates)) {
                throw new InvalidArgumentException("rates.uvg.classes.{$classCode} must be an object.");
            }

            $uvgClasses[(string) $classCode] = [
                'bu_ppm' => self::percent($classRates, 'bu', "uvg.classes.{$classCode}."),
                'nbu_ppm' => self::percent($classRates, 'nbu', "uvg.classes.{$classCode}."),
            ];
        }

        $fakCantons = [];
        $rawFakCantons = self::value($rates, 'fak.cantons') ?? [];

        if (! is_array($rawFakCantons)) {
            throw new InvalidArgumentException('rates.fak.cantons must be an object keyed by canton.');
        }

        foreach ($rawFakCantons as $canton => $rate) {
            $fakCantons[strtoupper((string) $canton)] = self::percent(['rate' => $rate], 'rate', "fak.cantons.{$canton}.");
        }

        $employerShare = self::percent($rates, 'bvg.employer_share');

        if ($employerShare < 0 || $employerShare > Rappen::PPM_PER_UNIT) {
            throw new InvalidArgumentException('rates.bvg.employer_share must be between 0 and 100.');
        }

        return new self(
            id: $id,
            code: $code,
            year: $year,
            ahvEmployeePpm: self::percent($rates, 'ahv_iv_eo.employee'),
            ahvEmployerPpm: self::percent($rates, 'ahv_iv_eo.employer'),
            ahvAdminCostPpm: self::percent($rates, 'ahv_admin_cost.employer'),
            ahvContributionStartAge: self::integer($rates, 'ahv_contribution_start_age'),
            referenceAge: self::integer($rates, 'reference_age'),
            retireeAllowanceMonthlyRappen: self::amount($rates, 'ahv_retiree_allowance_monthly'),
            alvEmployeePpm: self::percent($rates, 'alv.employee'),
            alvEmployerPpm: self::percent($rates, 'alv.employer'),
            alvCeilingAnnualRappen: self::amount($rates, 'alv.ceiling_annual'),
            alvSolidarityEmployeePpm: self::percent($rates, 'alv.solidarity_employee'),
            alvSolidarityEmployerPpm: self::percent($rates, 'alv.solidarity_employer'),
            bvgEntryThresholdRappen: self::amount($rates, 'bvg.entry_threshold'),
            bvgCoordinationDeductionRappen: self::amount($rates, 'bvg.coordination_deduction'),
            bvgUpperLimitRappen: self::amount($rates, 'bvg.upper_limit'),
            bvgMinCoordinatedSalaryRappen: self::amount($rates, 'bvg.min_coordinated_salary'),
            bvgEmployerSharePpm: $employerShare,
            bvgAgeCredits: $ageCredits,
            uvgMaxInsuredAnnualRappen: self::amount($rates, 'uvg.max_insured_annual'),
            uvgClasses: $uvgClasses,
            ktgEmployeePpm: self::percent($rates, 'ktg.employee'),
            ktgEmployerPpm: self::percent($rates, 'ktg.employer'),
            fakDefaultPpm: self::percent($rates, 'fak.default'),
            fakCantonRatesPpm: $fakCantons,
        );
    }

    /**
     * Total BVG retirement credit rate (employee + employer) for the BVG age (calendar year minus birth year).
     */
    public function bvgCreditRateForAge(int $bvgAge): int
    {
        foreach ($this->bvgAgeCredits as $band) {
            if ($bvgAge >= $band['from_age'] && $bvgAge <= $band['to_age']) {
                return $band['rate_ppm'];
            }
        }

        return 0;
    }

    /**
     * @return array{bu_ppm: int, nbu_ppm: int}|null
     */
    public function uvgClass(string $classCode): ?array
    {
        return $this->uvgClasses[$classCode] ?? null;
    }

    public function fakRateForCanton(string $canton): int
    {
        return $this->fakCantonRatesPpm[strtoupper($canton)] ?? $this->fakDefaultPpm;
    }

    /**
     * @param  array<array-key, mixed>  $source
     */
    private static function value(array $source, string $path): mixed
    {
        $current = $source;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param  array<array-key, mixed>  $source
     */
    private static function percent(array $source, string $path, string $prefix = ''): int
    {
        $value = self::value($source, $path);

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("rates.{$prefix}{$path} is required and must be a percentage.");
        }

        try {
            $ppm = Rappen::percentToPpm($value);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("rates.{$prefix}{$path} must be a decimal percentage.");
        }

        if ($ppm < 0 || $ppm > Rappen::PPM_PER_UNIT) {
            throw new InvalidArgumentException("rates.{$prefix}{$path} must be between 0 and 100.");
        }

        return $ppm;
    }

    /**
     * @param  array<array-key, mixed>  $source
     */
    private static function amount(array $source, string $path): int
    {
        $value = self::value($source, $path);

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("rates.{$path} is required and must be a CHF amount.");
        }

        try {
            $rappen = Rappen::fromDecimal($value);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("rates.{$path} must be a decimal CHF amount.");
        }

        if ($rappen < 0) {
            throw new InvalidArgumentException("rates.{$path} must not be negative.");
        }

        return $rappen;
    }

    /**
     * @param  array<array-key, mixed>  $source
     */
    private static function integer(array $source, string $path, string $prefix = ''): int
    {
        $value = self::value($source, $path);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("rates.{$prefix}{$path} is required and must be an integer.");
        }

        return (int) $value;
    }
}
