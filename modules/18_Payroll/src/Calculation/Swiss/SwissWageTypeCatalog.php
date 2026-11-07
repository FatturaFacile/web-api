<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use Modules\Payroll\Calculation\WageTypeData;
use Modules\Payroll\Calculation\WageTypeKind;

/**
 * The standard Swiss wage type catalog created for every Swiss company (editable, system types cannot be deleted).
 */
final class SwissWageTypeCatalog
{
    public const string ShortTimeReduction = '1910';

    public const string ShortTimeCompensation = '2030';

    public const string BadWeatherCompensation = '2040';

    /**
     * @return list<WageTypeData>
     */
    public static function create(): array
    {
        return [
            self::earning(SwissWageTypeCodes::MonthlySalary, 'Salario mensile', '1', system: true),
            self::earning(SwissWageTypeCodes::HourlyWage, 'Salario orario', '1', system: true),
            self::earning(SwissWageTypeCodes::HolidayCompensation, 'Indennità vacanze (salario orario)', '1', system: true),
            self::earning('1010', 'Ore straordinarie', '1'),
            self::earning('1020', 'Indennità turni / notturno / festivi', '1'),
            self::earning('1030', 'Provvigioni', '1'),
            self::irregular('1100', 'Gratifica / bonus', '3'),
            self::earning(SwissWageTypeCodes::ThirteenthSalary, '13a mensilità', '1', system: true),
            self::earning(SwissWageTypeCodes::UnpaidLeave, 'Riduzione per congedo non pagato', '1', system: true),
            self::allowance('2000', 'Indennità giornaliera IGM (perdita di guadagno malattia)'),
            self::allowance('2010', 'Indennità giornaliera LAINF (infortunio)'),
            self::allowance('2020', 'Indennità IPG (servizio militare, maternità, paternità)', subjectToAhv: true),
            self::family(SwissWageTypeCodes::ChildAllowance, 'Assegno per figli'),
            self::family(SwissWageTypeCodes::EducationAllowance, 'Assegno di formazione'),
            self::nonCash('4000', 'Vitto e alloggio (prestazione in natura)', '2.1'),
            self::nonCash('4010', 'Quota privata auto aziendale', '2.2'),
            self::nonCash('4020', 'Altre prestazioni accessorie', '2.3'),
            self::expense('5000', 'Rimborso spese effettive', '13.1.1'),
            self::expense('5010', 'Spese forfettarie di rappresentanza', '13.2.1'),
            self::expense('5020', 'Spese forfettarie auto', '13.2.2'),
            self::expense('5030', 'Altre spese forfettarie', '13.2.3'),
            self::deduction(SwissWageTypeCodes::Ahv, 'Contributo AVS/AI/IPG', '9', 900),
            self::deduction(SwissWageTypeCodes::Alv, 'Contributo AD (assicurazione disoccupazione)', '9', 910),
            self::deduction(SwissWageTypeCodes::Nbu, 'Assicurazione infortuni non professionali (AINP)', '9', 920),
            self::deduction(SwissWageTypeCodes::Ktg, 'Indennità giornaliera di malattia (IGM)', null, 930),
            self::deduction(SwissWageTypeCodes::Bvg, 'Previdenza professionale LPP', '10.1', 940),
            self::deduction(SwissWageTypeCodes::WithholdingTax, 'Imposta alla fonte', '12', 950),
            self::deduction('9060', 'Anticipo salario / altre trattenute', null, 960, system: false),
            self::employer(SwissWageTypeCodes::AhvEmployer, 'AVS/AI/IPG datore di lavoro', 1000),
            self::employer(SwissWageTypeCodes::AhvAdministration, 'Spese amministrative cassa AVS', 1005),
            self::employer(SwissWageTypeCodes::AlvEmployer, 'AD datore di lavoro', 1010),
            self::employer(SwissWageTypeCodes::BuEmployer, 'Assicurazione infortuni professionali (AINF)', 1020),
            self::employer(SwissWageTypeCodes::KtgEmployer, 'IGM datore di lavoro', 1030),
            self::employer(SwissWageTypeCodes::BvgEmployer, 'LPP datore di lavoro', 1040),
            self::employer(SwissWageTypeCodes::FakEmployer, 'Cassa assegni familiari (CAF)', 1050),
        ];
    }

    /**
     * The wage types of short-time work and bad weather, created when a company first applies a claim. The reduction stays
     * out of the social bases because contributions run on the full salary during short-time work (SECO 716.400, question
     * 13); the withholding tax follows what is really paid. The certificate box 7 follows the other replacement incomes of
     * this catalog and is not confirmed by a source.
     *
     * @return list<WageTypeData>
     */
    public static function shortTimeTypes(): array
    {
        return [
            new WageTypeData(
                code: self::ShortTimeReduction,
                name: 'Riduzione per lavoro ridotto / intemperie',
                kind: WageTypeKind::Earning,
                subjectToWithholdingTax: true,
                certificateBox: '1',
                sortOrder: self::order(self::ShortTimeReduction),
            ),
            self::allowance(self::ShortTimeCompensation, 'Indennità per lavoro ridotto'),
            self::allowance(self::BadWeatherCompensation, 'Indennità per intemperie'),
        ];
    }

    /**
     * @return array<string, WageTypeData>
     */
    public static function byCode(): array
    {
        $types = [];

        foreach (self::create() as $type) {
            $types[$type->code] = $type;
        }

        return $types;
    }

    private static function earning(string $code, string $name, string $box, bool $system = false): WageTypeData
    {
        return new WageTypeData(
            code: $code,
            name: $name,
            kind: WageTypeKind::Earning,
            subjectToAhv: true,
            subjectToAlv: true,
            subjectToUvg: true,
            subjectToKtg: true,
            subjectToBvg: true,
            subjectToWithholdingTax: true,
            certificateBox: $box,
            sortOrder: self::order($code),
            isSystem: $system,
        );
    }

    private static function irregular(string $code, string $name, string $box): WageTypeData
    {
        return new WageTypeData(
            code: $code,
            name: $name,
            kind: WageTypeKind::Earning,
            subjectToAhv: true,
            subjectToAlv: true,
            subjectToUvg: true,
            subjectToKtg: true,
            subjectToBvg: false,
            subjectToWithholdingTax: true,
            isIrregular: true,
            certificateBox: $box,
            sortOrder: self::order($code),
        );
    }

    private static function allowance(string $code, string $name, bool $subjectToAhv = false, string $box = '7', bool $system = false): WageTypeData
    {
        return new WageTypeData(
            code: $code,
            name: $name,
            kind: WageTypeKind::Earning,
            subjectToAhv: $subjectToAhv,
            subjectToAlv: $subjectToAhv,
            subjectToWithholdingTax: true,
            certificateBox: $box,
            sortOrder: self::order($code),
            isSystem: $system,
        );
    }

    private static function family(string $code, string $name): WageTypeData
    {
        return self::allowance($code, $name, box: '1', system: true);
    }

    private static function nonCash(string $code, string $name, string $box): WageTypeData
    {
        return new WageTypeData(
            code: $code,
            name: $name,
            kind: WageTypeKind::NonCash,
            subjectToAhv: true,
            subjectToAlv: true,
            subjectToUvg: true,
            subjectToWithholdingTax: true,
            certificateBox: $box,
            sortOrder: self::order($code),
        );
    }

    private static function expense(string $code, string $name, string $box): WageTypeData
    {
        return new WageTypeData(code: $code, name: $name, kind: WageTypeKind::Expense, certificateBox: $box, sortOrder: self::order($code));
    }

    private static function deduction(string $code, string $name, ?string $box, int $order, bool $system = true): WageTypeData
    {
        return new WageTypeData(code: $code, name: $name, kind: WageTypeKind::Deduction, certificateBox: $box, sortOrder: $order, isSystem: $system);
    }

    private static function employer(string $code, string $name, int $order): WageTypeData
    {
        return new WageTypeData(code: $code, name: $name, kind: WageTypeKind::Employer, sortOrder: $order, isSystem: true);
    }

    private static function order(string $code): int
    {
        return intdiv((int) $code, 10);
    }
}
