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

    /** Sickness is certified by an INPS protocol number that the consultant needs to pay the indemnity. */
    public function requiresInpsProtocol(): bool
    {
        return $this === self::Malattia;
    }

    /** Overtime is counted in hours, every other leave in days. */
    public function isHours(): bool
    {
        return in_array($this, [self::Rol, self::Straordinario25, self::Straordinario50, self::Ordinario, self::CassaIntegrazione], true);
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
