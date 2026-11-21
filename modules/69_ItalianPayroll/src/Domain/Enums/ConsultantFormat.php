<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Domain\Enums;

/**
 * The payroll software of the labour consultant (consulente del lavoro) the attendance is exported to.
 */
enum ConsultantFormat: string
{
    case Zucchetti = 'zucchetti';
    case TeamSystem = 'teamsystem';
    case Inaz = 'inaz';
    case Gis = 'gis';
    case GenericCsv = 'generic_csv';

    /**
     * The voice code the consultant software expects for a causal when the tenant has not mapped its own.
     */
    public function defaultVoiceCode(CausalCode $causal): string
    {
        return match ($this) {
            self::Zucchetti => match ($causal) {
                CausalCode::Ferie => 'FERI', CausalCode::Rol => 'ROL', CausalCode::ExFestivita => 'EXFE', CausalCode::Malattia => 'MALA',
                CausalCode::Maternita => 'MATE', CausalCode::Infortunio => 'INFO', CausalCode::CassaIntegrazione => 'CIGO',
                CausalCode::Straordinario25 => 'ST25', CausalCode::Straordinario50 => 'ST50', CausalCode::Ordinario => 'ORDI',
            },
            self::TeamSystem => match ($causal) {
                CausalCode::Ferie => '0100', CausalCode::Rol => '0110', CausalCode::ExFestivita => '0120', CausalCode::Malattia => '0200',
                CausalCode::Maternita => '0300', CausalCode::Infortunio => '0210', CausalCode::CassaIntegrazione => '0400',
                CausalCode::Straordinario25 => '0510', CausalCode::Straordinario50 => '0520', CausalCode::Ordinario => '0001',
            },
            self::Inaz => match ($causal) {
                CausalCode::Ferie => 'F', CausalCode::Rol => 'R', CausalCode::ExFestivita => 'X', CausalCode::Malattia => 'M',
                CausalCode::Maternita => 'T', CausalCode::Infortunio => 'I', CausalCode::CassaIntegrazione => 'C',
                CausalCode::Straordinario25 => 'S1', CausalCode::Straordinario50 => 'S2', CausalCode::Ordinario => 'O',
            },
            self::Gis => match ($causal) {
                CausalCode::Ferie => 'FE', CausalCode::Rol => 'RL', CausalCode::ExFestivita => 'EF', CausalCode::Malattia => 'MA',
                CausalCode::Maternita => 'MT', CausalCode::Infortunio => 'IN', CausalCode::CassaIntegrazione => 'CG',
                CausalCode::Straordinario25 => 'S25', CausalCode::Straordinario50 => 'S50', CausalCode::Ordinario => 'OR',
            },
            self::GenericCsv => $causal->value,
        };
    }
}
