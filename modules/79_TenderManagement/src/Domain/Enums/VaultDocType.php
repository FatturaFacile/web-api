<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

/**
 * Kind of document held in the company qualification vault.
 */
enum VaultDocType: string
{
    case ItSoa = 'IT_SOA_Attestation';
    case ItDurc = 'IT_DURC';
    case ItVisura = 'IT_VisuraCamerale';
    case ItCasellario = 'IT_CasellarioGiudiziale_CarichiPendenti';
    case ItIso9001 = 'IT_ISO9001';
    case ItIso14001 = 'IT_ISO14001';
    case ItIso45001 = 'IT_ISO45001';
    case ItPdr125 = 'IT_UNI_PdR_125_ParitaGenere';
    case ItEmas = 'IT_EMAS';
    case ItPmi = 'IT_PMI_Status';
    case ChBetreibungsauszug = 'CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug';
    case ChAvs = 'CH_AttestatoAVS_AI_IPG';
    case ChSuva = 'CH_AttestatoSUVA';
    case ChLpp = 'CH_AttestatoLPP';
    case ChAfcVat = 'CH_AttestatoAFC_IVA';
    case ChCcl = 'CH_RispettoCCL';
    case ChLogib = 'CH_ParitaSalarialeLogib';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isItalian(): bool
    {
        return str_starts_with($this->value, 'IT_');
    }

    public function isSwiss(): bool
    {
        return str_starts_with($this->value, 'CH_');
    }

    /**
     * Certifications that can reduce the provisional bid bond.
     */
    public function bondReductionKey(): ?string
    {
        return match ($this) {
            self::ItIso9001 => 'iso9001',
            self::ItIso14001 => 'iso14001',
            self::ItPdr125 => 'pdr125',
            self::ItEmas => 'emas',
            self::ItPmi => 'pmi',
            default => null,
        };
    }

    /**
     * Months the document stays acceptable after its issue date, when it carries no expiry date of its own.
     */
    public function maxAgeMonths(): ?int
    {
        return match ($this) {
            self::ChBetreibungsauszug => (int) config('tender_management.ch.betreibungsauszug_max_age_months', 3),
            self::ItVisura, self::ItCasellario => 6,
            default => null,
        };
    }
}
