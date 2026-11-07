<?php

declare(strict_types=1);

namespace Modules\Intrastat\Services;

use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Enums\Periodicity;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Models\IntrastatDeclarationLine;
use Modules\Intrastat\Support\IntrastatCodes;
use Modules\Intrastat\Support\PeriodicityRules;

/**
 * Checks a list against the instructions before it is exported. Every rule refers to the columns of the models in
 * Allegato XI (instructions) and the fields of Allegato XII (technical specifications) of the determinazione ADM prot.
 * n. 493869/RU del 23 dicembre 2021:
 * https://www.adm.gov.it/portale/documents/20182/23456462/Allegato+11+Istruzioni+per+l%27uso+e+la+compilazione+degli+elenchi+riepilogativi.pdf/e7887bad-97cc-97bc-4761-6e37b1ec5b39?t=1640615553784
 * https://www.adm.gov.it/portale/documents/20182/23456462/Allegato+12+Specifiche+tecniche+e+tracciati+record+degli+elenchi+riepilogativi.pdf/70a17dc9-8170-784c-7868-f154ec6990c8?t=1640615575812
 *
 * Which columns are mandatory:
 * - INTRA-1 bis, quarterly or monthly fiscal only: columns 1 to 7; natura A only for triangular operations, CN8 optional.
 *   Monthly with statistical data: all columns; natura B, statistical value, delivery terms and transport mode only for
 *   the parties of art. 6 c. 4 DM 22/02/2010; net mass not required from the others when the CN8 has a supplementary
 *   unit, which this module infers from the supplementary units being given.
 * - INTRA-1 ter: month or quarter and year of the corrected list, partner, sign, amount; natura A and CN8 when a monthly
 *   list is corrected (Sezione 2, note 4).
 * - INTRA-1 quater: partner, amount, service code, country of payment; invoice, supply mode and payment method optional.
 * - INTRA-1 quinquies / INTRA-2 quinquies: customs section, year and protocol of the corrected list and row number;
 *   all other columns empty on a cancellation, filled on a change.
 * - INTRA-2 bis: statistical only; supplier state and VAT code optional from 2022; natura A numeric (no triangular
 *   letters from 2018), CN8, net mass (same exception), country of provenance, country of origin, province of
 *   destination.
 * - INTRA-2 ter: month (quarter only for periods up to 2021), year, sign, amount, natura A, CN8.
 * - INTRA-2 quater: state, amount, service code; VAT code and country of payment optional from 2022.
 * Services with Northern Ireland (XI) are excluded (Tabella A, note 2).
 */
final class DeclarationValidator
{
    /**
     * @var list<array{line_id: string|null, section: int|null, line_number: int|null, field: string, message: string}>
     */
    private array $errors = [];

    /**
     * @return list<array{line_id: string|null, section: int|null, line_number: int|null, field: string, message: string}>
     */
    public function validate(IntrastatDeclaration $declaration): array
    {
        $this->errors = [];
        $declaration->loadMissing('lines');

        $this->checkFrontespizio($declaration);

        foreach ($declaration->lines as $line) {
            match ($line->section) {
                1 => $this->checkGoods($declaration, $line),
                2 => $this->checkGoodsCorrection($declaration, $line),
                3 => $this->checkServices($declaration, $line),
                4 => $this->checkServicesCorrection($declaration, $line),
                default => $this->fail($line, 'section', 'Unknown section.'),
            };
        }

        return $this->errors;
    }

    private function checkFrontespizio(IntrastatDeclaration $declaration): void
    {
        if (! $this->isElevenDigits($declaration->obligated_vat_number)) {
            $this->fail(null, 'obligated_vat_number', 'The VAT number of the obliged party must have 11 digits.');
        }

        if ($declaration->delegate_vat_number !== null && ! $this->isElevenDigits($declaration->delegate_vat_number)) {
            $this->fail(null, 'delegate_vat_number', 'The VAT number of the delegate must have 11 digits.');
        }

        if ($declaration->year < PeriodicityRules::FirstSupportedYear) {
            $this->fail(null, 'year', 'Only periods from 2022 are supported.');
        }

        if (! DeclarationGenerator::allowsPeriodicity($declaration->flow, $declaration->periodicity)) {
            $this->fail(null, 'periodicity', 'The quarterly INTRA-2 lists are abolished from 2022: purchases are filed monthly.');
        }

        if ($declaration->period < 1 || $declaration->period > $declaration->periodicity->lastPeriod()) {
            $this->fail(null, 'period', 'The period is out of range for the periodicity.');
        }

        if (! array_key_exists($declaration->content_code, IntrastatCodes::ContentCodes)
            || ($declaration->periodicity === Periodicity::Monthly && $declaration->content_code !== 0)) {
            $this->fail(null, 'content_code', 'Codes 8 and 9 are only for quarterly lists after a change of periodicity; use 0 otherwise.');
        }

        if (! array_key_exists($declaration->special_case_code, IntrastatCodes::SpecialCaseCodes)) {
            $this->fail(null, 'special_case_code', 'Allowed values: 0, 7, 8, 9.');
        }

        if ($declaration->reference_number < 1 || $declaration->reference_number > 999_999) {
            $this->fail(null, 'reference_number', 'The reference number has at most 6 digits.');
        }

        if ($declaration->lines->isEmpty()) {
            $this->fail(null, 'lines', 'A list has at least one detail row.');
        }

        foreach ($declaration->lines->groupBy('section') as $section => $lines) {
            if ($lines->count() > 99_999) {
                $this->fail(null, 'lines', "Section {$section} has more than 99999 rows.");
            }
        }
    }

    private function checkGoods(IntrastatDeclaration $declaration, IntrastatDeclarationLine $line): void
    {
        $isSales = $declaration->flow === IntrastatFlow::Sales;
        $statistical = $declaration->requiresStatisticalData();
        $extended = $declaration->has_annual_flows_above_20_million && $statistical;
        $triangular = IntrastatCodes::isTriangularNature($line->transaction_nature_a);

        if ($isSales) {
            $this->checkPartner($line, required: true, services: false);
        } else {
            $this->checkPartner($line, required: false, services: false);
        }

        $this->requireAmount($line, 'amount_eur', $line->statistical_value_eur === null);

        if ($triangular) {
            if (! $isSales) {
                $this->fail($line, 'transaction_nature_a', 'Triangular operations are not reported in INTRA-2 bis.');
            }

            foreach (['transaction_nature_b', 'net_mass_kg', 'supplementary_units', 'statistical_value_eur', 'delivery_terms', 'transport_mode', 'goods_country_code', 'origin_country_code', 'province_code'] as $field) {
                if ($line->{$field} !== null) {
                    $this->fail($line, $field, 'A triangular operation carries the fiscal data only.');
                }
            }

            return;
        }

        $this->checkNatureA($line, required: $statistical);
        $this->checkCombinedNomenclature($line, 'cn8_code', required: $statistical);

        if (! $statistical) {
            foreach (['transaction_nature_b', 'net_mass_kg', 'supplementary_units', 'statistical_value_eur', 'delivery_terms', 'transport_mode', 'goods_country_code', 'origin_country_code', 'province_code'] as $field) {
                if ($line->{$field} !== null) {
                    $this->fail($line, $field, $declaration->periodicity === Periodicity::Quarterly
                        ? 'The quarterly INTRA-1 bis carries columns 1 to 7 only.'
                        : 'The list is declared without statistical data: set includes_statistical_data or clear the column.');
                }
            }

            return;
        }

        $this->checkNatureB($line, required: $extended);

        if ($line->net_mass_kg === null && ($extended || $line->supplementary_units === null)) {
            $this->fail($line, 'net_mass_kg', 'The net mass in kg is missing.');
        }

        if ($extended && $line->statistical_value_eur === null) {
            $this->fail($line, 'statistical_value_eur', 'The statistical value is required from parties above 20 million euro of annual flows.');
        }

        $this->checkCode($line, 'delivery_terms', array_keys(IntrastatCodes::DeliveryTermGroups), $extended, 'The delivery terms group (E, F, C, D)');
        $this->checkCode($line, 'transport_mode', array_map('strval', array_keys(IntrastatCodes::TransportModes)), $extended, 'The transport mode');
        $this->checkMemberState($line, 'goods_country_code', $isSales ? 'The country of destination' : 'The country of provenance');
        $this->checkCountryFormat($line, 'origin_country_code', 'The country of origin', required: true);
        $this->checkProvince($line, $isSales ? 'The province of origin' : 'The province of destination');
    }

    private function checkGoodsCorrection(IntrastatDeclaration $declaration, IntrastatDeclarationLine $line): void
    {
        $isSales = $declaration->flow === IntrastatFlow::Sales;
        $month = (int) $line->corrected_month;
        $quarter = (int) $line->corrected_quarter;

        if ($line->corrected_year === null || $line->corrected_year < 2000 || $line->corrected_year > $declaration->year) {
            $this->fail($line, 'corrected_year', 'The year of the corrected list is missing or invalid.');
        }

        if ($isSales) {
            if (! (($month >= 1 && $month <= 12 && $quarter === 0) || ($quarter >= 1 && $quarter <= 4 && $month === 0))) {
                $this->fail($line, 'corrected_month', 'Give either the month (1-12) or the quarter (1-4) of the corrected list, and zero in the other.');
            }
        } elseif ($month < 1 || $month > 12 || $quarter !== 0) {
            $this->fail($line, 'corrected_month', 'Give the month (1-12) of the corrected list; quarters are only for periods up to 2021.');
        }

        $this->checkPartner($line, required: $isSales, services: false);
        $this->checkCode($line, 'sign', ['+', '-'], true, 'The sign');
        $this->requireAmount($line, 'amount_eur', true);

        if ($line->transaction_nature_a !== null || $month > 0 || ! $isSales) {
            $this->checkNatureA($line, required: $month > 0 || ! $isSales, allowTriangular: $isSales);
        }

        $this->checkCombinedNomenclature($line, 'cn8_code', required: $month > 0 || ! $isSales);

        if ($line->statistical_value_eur !== null && $declaration->periodicity === Periodicity::Quarterly) {
            $this->fail($line, 'statistical_value_eur', 'The quarterly INTRA-1 ter has no statistical value.');
        }

        foreach (['transaction_nature_b', 'net_mass_kg', 'supplementary_units', 'delivery_terms', 'transport_mode', 'goods_country_code', 'origin_country_code', 'province_code', 'service_code'] as $field) {
            if ($line->{$field} !== null) {
                $this->fail($line, $field, 'Not a column of the correction section.');
            }
        }
    }

    private function checkServices(IntrastatDeclaration $declaration, IntrastatDeclarationLine $line): void
    {
        $isSales = $declaration->flow === IntrastatFlow::Sales;

        $this->checkPartner($line, required: true, services: true, vatRequired: $isSales);
        $this->requireAmount($line, 'amount_eur', true);
        $this->checkServiceDetails($line);
        $this->checkCountryFormat($line, 'payment_country_code', 'The country of payment', required: $isSales);
    }

    private function checkServicesCorrection(IntrastatDeclaration $declaration, IntrastatDeclarationLine $line): void
    {
        $isSales = $declaration->flow === IntrastatFlow::Sales;

        if (preg_match('/^\d{1,6}$/', (string) $line->corrected_customs_section) !== 1) {
            $this->fail($line, 'corrected_customs_section', 'The customs section of the corrected list (up to 6 digits) is missing.');
        }

        if ($line->corrected_protocol_year === null || $line->corrected_protocol_year < 2000) {
            $this->fail($line, 'corrected_protocol_year', 'The year of the protocol of the corrected list is missing.');
        }

        if (preg_match('/^\d{1,6}$/', (string) $line->corrected_protocol_number) !== 1) {
            $this->fail($line, 'corrected_protocol_number', 'The protocol of the corrected list (up to 6 digits, without the CIN check character) is missing.');
        }

        if ($line->corrected_line_number === null || $line->corrected_line_number < 1 || $line->corrected_line_number > 99_999) {
            $this->fail($line, 'corrected_line_number', 'The row number of section 3 to correct is missing.');
        }

        if ($line->is_cancellation) {
            foreach (['partner_country_code', 'partner_vat_number', 'amount_eur', 'amount_currency', 'invoice_number', 'invoice_date', 'service_code', 'supply_mode', 'payment_method', 'payment_country_code'] as $field) {
                if ($line->{$field} !== null) {
                    $this->fail($line, $field, 'A cancellation leaves every other column empty.');
                }
            }

            return;
        }

        $this->checkPartner($line, required: true, services: true, vatRequired: $isSales);
        $this->requireAmount($line, 'amount_eur', true);
        $this->checkServiceDetails($line);
        $this->checkCountryFormat($line, 'payment_country_code', 'The country of payment', required: $isSales);
    }

    private function checkServiceDetails(IntrastatDeclarationLine $line): void
    {
        if (preg_match('/^\d{6}$/', (string) $line->service_code) !== 1) {
            $this->fail($line, 'service_code', 'The CPA service code (6 digits) is missing or invalid.');
        }

        if ($line->invoice_number !== null && preg_match('/^[\x20-\x7E]{1,15}$/', $line->invoice_number) !== 1) {
            $this->fail($line, 'invoice_number', 'The invoice number has at most 15 ASCII characters.');
        }

        $this->checkCode($line, 'supply_mode', array_keys(IntrastatCodes::SupplyModes), false, 'The supply mode');
        $this->checkCode($line, 'payment_method', array_keys(IntrastatCodes::PaymentMethods), false, 'The payment method');

        foreach (['transaction_nature_a', 'transaction_nature_b', 'cn8_code', 'net_mass_kg', 'supplementary_units', 'statistical_value_eur', 'delivery_terms', 'transport_mode', 'goods_country_code', 'origin_country_code', 'province_code'] as $field) {
            if ($line->{$field} !== null) {
                $this->fail($line, $field, 'Not a column of the services sections.');
            }
        }
    }

    private function checkPartner(IntrastatDeclarationLine $line, bool $required, bool $services, ?bool $vatRequired = null): void
    {
        $vatRequired ??= $required;
        $country = $line->partner_country_code;
        $isKnownCountry = false;

        if ($country === null) {
            if ($required) {
                $this->fail($line, 'partner_country_code', 'The member state of the partner is missing.');
            }
        } elseif (! IntrastatCodes::isPartnerMemberState($country) || IntrastatCodes::memberState($country) !== $country) {
            $this->fail($line, 'partner_country_code', 'Not a member state code of Tabella A (Greece is EL).');
        } elseif ($services && $country === IntrastatCodes::NorthernIreland) {
            $this->fail($line, 'partner_country_code', 'Services with Northern Ireland (XI) are not reported.');
        } else {
            $isKnownCountry = true;
        }

        if ($line->partner_vat_number === null) {
            if ($vatRequired) {
                $this->fail($line, 'partner_vat_number', 'The VAT code of the partner is missing.');
            }
        } elseif ($isKnownCountry && ! IntrastatCodes::isValidVatCode($country, $line->partner_vat_number)) {
            $lengths = implode(' or ', IntrastatCodes::MemberStateVatLengths[$country] ?? []);
            $this->fail($line, 'partner_vat_number', "The VAT code of {$country} has {$lengths} characters, without the country code.");
        }
    }

    private function requireAmount(IntrastatDeclarationLine $line, string $field, bool $required): void
    {
        if ($line->{$field} === null) {
            if ($required) {
                $this->fail($line, $field, 'The amount in euro is missing.');
            }

            return;
        }

        if ($line->{$field} > 9_999_999_999_999) {
            $this->fail($line, $field, 'The amount has more than 13 digits.');
        }
    }

    private function checkNatureA(IntrastatDeclarationLine $line, bool $required, bool $allowTriangular = false): void
    {
        $nature = $line->transaction_nature_a;

        if ($nature === null) {
            if ($required) {
                $this->fail($line, 'transaction_nature_a', 'The nature of the transaction (column A) is missing.');
            }

            return;
        }

        $valid = array_key_exists($nature, IntrastatCodes::TransactionNatures) || ($allowTriangular && IntrastatCodes::isTriangularNature($nature));

        if (! $valid) {
            $this->fail($line, 'transaction_nature_a', 'Not a code of Tabella B, column A.');
        }
    }

    private function checkNatureB(IntrastatDeclarationLine $line, bool $required): void
    {
        $natureB = $line->transaction_nature_b;

        if ($natureB === null) {
            if ($required) {
                $this->fail($line, 'transaction_nature_b', 'The nature of the transaction (column B) is required from parties above 20 million euro of annual flows.');
            }

            return;
        }

        $allowed = IntrastatCodes::TransactionNatures[(string) $line->transaction_nature_a] ?? [];

        if (! in_array($natureB, $allowed, true)) {
            $this->fail($line, 'transaction_nature_b', 'Not a code of Tabella B, column B, for the given column A.');
        } elseif (in_array($line->transaction_nature_a.$natureB, IntrastatCodes::NotReportedTransactionNatures, true)) {
            $this->fail($line, 'transaction_nature_b', 'Nature 12 (direct trade with private consumers) is not reported in the INTRA lists.');
        }
    }

    private function checkCombinedNomenclature(IntrastatDeclarationLine $line, string $field, bool $required): void
    {
        if ($line->{$field} === null) {
            if ($required) {
                $this->fail($line, $field, 'The combined nomenclature code (CN8) is missing.');
            }

            return;
        }

        if (preg_match('/^\d{8}$/', (string) $line->{$field}) !== 1) {
            $this->fail($line, $field, 'The combined nomenclature code has 8 digits.');
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function checkCode(IntrastatDeclarationLine $line, string $field, array $allowed, bool $required, string $label): void
    {
        $value = $line->{$field};

        if ($value === null) {
            if ($required) {
                $this->fail($line, $field, "{$label} is missing.");
            }

            return;
        }

        if (! in_array((string) $value, $allowed, true)) {
            $this->fail($line, $field, "{$label} must be one of: ".implode(', ', $allowed).'.');
        }
    }

    private function checkMemberState(IntrastatDeclarationLine $line, string $field, string $label): void
    {
        $value = $line->{$field};

        if ($value === null) {
            $this->fail($line, $field, "{$label} is missing.");
        } elseif (! IntrastatCodes::isPartnerMemberState($value) || IntrastatCodes::memberState($value) !== $value) {
            $this->fail($line, $field, "{$label} must be a member state code of Tabella A other than IT.");
        }
    }

    private function checkCountryFormat(IntrastatDeclarationLine $line, string $field, string $label, bool $required): void
    {
        $value = $line->{$field};

        if ($value === null) {
            if ($required) {
                $this->fail($line, $field, "{$label} is missing.");
            }
        } elseif (preg_match('/^[A-Z]{2}$/', $value) !== 1) {
            $this->fail($line, $field, "{$label} is a two-letter ISO code.");
        }
    }

    private function checkProvince(IntrastatDeclarationLine $line, string $label): void
    {
        if ($line->province_code === null) {
            $this->fail($line, 'province_code', "{$label} is missing.");
        } elseif (preg_match('/^[A-Z]{2}$/', $line->province_code) !== 1) {
            $this->fail($line, 'province_code', "{$label} is the two-letter vehicle registration code of the province.");
        }
    }

    private function isElevenDigits(?string $value): bool
    {
        return preg_match('/^\d{11}$/', (string) $value) === 1;
    }

    private function fail(?IntrastatDeclarationLine $line, string $field, string $message): void
    {
        $this->errors[] = [
            'line_id' => $line?->id,
            'section' => $line?->section,
            'line_number' => $line?->line_number,
            'field' => $field,
            'message' => $message,
        ];
    }
}
