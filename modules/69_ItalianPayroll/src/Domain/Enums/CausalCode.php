<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Domain\Enums;

/**
 * The internal attendance causals handed to the labour consultant. Each one has a default voice code per consultant
 * software (ConsultantFormat::defaultVoiceCode); a tenant overrides them in ipp_causal_mappings.
 */
enum CausalCode: string
{
    case Ferie = 'FER';
    case Rol = 'ROL';
    case ExFestivita = 'EXF';
    case Malattia = 'MAL';
    case Maternita = 'MAT';
    case Infortunio = 'INF';
    case CassaIntegrazione = 'CIG';
    case Straordinario25 = 'STR25';
    case Straordinario50 = 'STR50';
    case Ordinario = 'ORD';
    case MaggiorazioneNotturna = 'NOT';
    case MaggiorazioneDomenicale = 'DOM';
    case MaggiorazioneFestiva = 'FES';
    case Reperibilita = 'REP';

    /** Sickness is certified by an INPS protocol number that the consultant needs to pay the indemnity. */
    public function requiresInpsProtocol(): bool
    {
        return $this === self::Malattia;
    }

    /** Overtime is counted in hours, every other leave in days. */
    public function isHours(): bool
    {
        return in_array($this, [
            self::Rol, self::Straordinario25, self::Straordinario50, self::Ordinario, self::CassaIntegrazione,
            self::MaggiorazioneNotturna, self::MaggiorazioneDomenicale, self::MaggiorazioneFestiva, self::Reperibilita,
        ], true);
    }

    /**
     * Maps a Mod 074 leave type code (lm_leave_requests.leave_type) to the causal, null when the leave is not reported to the consultant.
     */
    public static function fromLeaveTypeCode(string $leaveType): ?self
    {
        return match ($leaveType) {
            'AnnualVacation_Ferie_Ferien' => self::Ferie,
            'IT_PermessiROL' => self::Rol,
            'IT_ExFestivita' => self::ExFestivita,
            'SickLeave_Malattia' => self::Malattia,
            'Accident_Infortunio_SUVA_INAIL' => self::Infortunio,
            'MaternityPaternity_Congedo' => self::Maternita,
            default => null,
        };
    }

    /**
     * Maps an HR leave type category (hr_leave_types.category) to the causal, null when the leave is not reported.
     */
    public static function fromLeaveCategory(string $category, string $code): ?self
    {
        return match (strtolower($category)) {
            'vacation' => self::Ferie,
            'rol' => self::Rol,
            'sick' => self::Malattia,
            'maternity' => self::Maternita,
            default => self::tryFrom(strtoupper($code)),
        };
    }
}
