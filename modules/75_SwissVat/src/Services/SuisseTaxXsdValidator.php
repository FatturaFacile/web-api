<?php

declare(strict_types=1);

namespace Modules\SwissVat\Services;

use DOMDocument;
use Modules\SwissVat\Domain\ValueObjects\QuadraturaReport;
use Modules\SwissVat\Domain\ValueObjects\SuisseTaxValidationResult;
use Modules\SwissVat\Domain\ValueObjects\SwissUidMwst;
use Modules\SwissVat\Models\SwissVatConfig;
use Modules\SwissVat\Models\SwissVatReturn;

/**
 * Validates SuisseTax XML payloads against the eCH-0217 v2.0 XSD schema plus Swiss VAT business/quadratura rules.
 */
final class SuisseTaxXsdValidator
{
    public function schemaPath(): string
    {
        return dirname(__DIR__, 2).'/resources/xsd/suissetax-ech0217-v2.xsd';
    }

    /**
     * @return list<string>
     */
    public function xsdErrors(string $xml): array
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (! $doc->loadXML($xml)) {
                return [__('swiss_vat.errors.xml_malformed')];
            }

            if (! $doc->schemaValidate($this->schemaPath())) {
                $errors = array_map(static fn ($err): string => trim((string) $err->message), libxml_get_errors());

                return $errors !== [] ? $errors : [__('swiss_vat.errors.xsd_validation_failed')];
            }

            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function validate(string $xml, SwissVatReturn $vatReturn, ?SwissVatConfig $config, QuadraturaReport $quadratura): SuisseTaxValidationResult
    {
        $xsdErrors = $this->xsdErrors($xml);
        $businessErrors = [];

        $uidRaw = $config?->uid_mwst;
        if ($uidRaw === null || trim($uidRaw) === '' || str_contains($uidRaw, '000.000.000')) {
            $businessErrors[] = __('swiss_vat.errors.uid_mwst_missing');
        } else {
            $digits = (string) preg_replace('/\D/', '', $uidRaw);
            if (strlen($digits) !== 9 || ! SwissUidMwst::hasValidMod11CheckDigit($digits)) {
                $businessErrors[] = __('swiss_vat.errors.uid_mwst_checksum_invalid', ['uid' => $uidRaw]);
            }
        }

        if (! $quadratura->passed) {
            foreach ($quadratura->blockers() as $blocker) {
                $businessErrors[] = sprintf('[%s] %s', $blocker->code, __($blocker->messageKey, $blocker->params));
            }
        }

        if ($vatReturn->period_to->lt($vatReturn->period_from)) {
            $businessErrors[] = __('swiss_vat.errors.period_inverted');
        }

        return new SuisseTaxValidationResult(
            valid: $xsdErrors === [] && $businessErrors === [],
            xsdErrors: $xsdErrors,
            businessErrors: $businessErrors,
            quadraturaPassed: $quadratura->passed,
        );
    }
}
