<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Services\ItalianSdi\FatturaPaBuilderService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Modules\SDI\DTOs\ValidationResult;
use Modules\SDI\Enums\FpaDirection;
use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Models\FpaDocument;

/**
 * Tier 2 of the pre-flight: the semantic and fiscal congruity rules SDI applies after the schema check. Codes are the
 * official SDI error codes where one exists; they are the keys the frontend translates.
 */
final class SogeiCongruityValidator
{
    private const float Tolerance = 0.011;

    /** @var list<string> */
    private const array NaturePrefixes = ['N1', 'N2.1', 'N2.2', 'N3.1', 'N3.2', 'N3.3', 'N3.4', 'N3.5', 'N3.6', 'N4', 'N5', 'N6.1', 'N6.2', 'N6.3', 'N6.4', 'N6.5', 'N6.6', 'N6.7', 'N6.8', 'N6.9', 'N7'];

    /** @var list<string> */
    private const array SelfInvoiceTypes = ['TD16', 'TD17', 'TD18', 'TD19'];

    public function __construct(private readonly FatturaPaBuilderService $fiscal = new FatturaPaBuilderService) {}

    public function validate(string $xml, ?string $tenantId = null, ?string $ignoreDocumentId = null): ValidationResult
    {
        $result = new ValidationResult;
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $dom->loadXML($xml, LIBXML_NONET)) {
                $result->error('XML_NOT_WELL_FORMED');

                return $result;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xp = new DOMXPath($dom);
        $format = $this->text($xp, '//*[local-name()="FormatoTrasmissione"]');

        $this->checkTransmission($xp, $format, $result);
        $this->checkIdentities($xp, $result);
        $this->checkLines($xp, $result);
        $this->checkSummary($xp, $result);
        $this->checkDocumentRules($xp, $format, $result);

        if ($tenantId !== null) {
            $this->checkDuplicate($xp, $tenantId, $ignoreDocumentId, $result);
        }

        return $result;
    }

    private function checkTransmission(DOMXPath $xp, string $format, ValidationResult $result): void
    {
        $code = $this->text($xp, '//*[local-name()="DatiTrasmissione"]/*[local-name()="CodiceDestinatario"]');
        $pec = $this->text($xp, '//*[local-name()="DatiTrasmissione"]/*[local-name()="PECDestinatario"]');
        $expected = $format === 'FPA12' ? 6 : 7;

        if (strlen($code) !== $expected) {
            $result->error('00311', 'DatiTrasmissione/CodiceDestinatario', ['expected_length' => $expected, 'format' => $format]);
        }

        if ($pec !== '' && $code !== '0000000') {
            $result->error('00426', 'DatiTrasmissione/PECDestinatario');
        }

        if ($format === 'FPR12' && $code === '0000000' && $pec === '') {
            $result->warning('00427', 'DatiTrasmissione/CodiceDestinatario');
        }
    }

    private function checkIdentities(DOMXPath $xp, ValidationResult $result): void
    {
        foreach (['CedentePrestatore', 'CessionarioCommittente'] as $party) {
            $base = '//*[local-name()="'.$party.'"]/*[local-name()="DatiAnagrafici"]';
            $country = $this->text($xp, $base.'/*[local-name()="IdFiscaleIVA"]/*[local-name()="IdPaese"]');
            $vat = $this->text($xp, $base.'/*[local-name()="IdFiscaleIVA"]/*[local-name()="IdCodice"]');
            $fiscalCode = $this->text($xp, $base.'/*[local-name()="CodiceFiscale"]');

            if ($country === 'IT' && $vat !== '' && ! $this->fiscal->isValidPartitaIva($vat)) {
                $result->error('00301', $party.'/IdFiscaleIVA/IdCodice', ['value' => $vat]);
            }

            if ($fiscalCode !== '' && ! $this->fiscal->isValidCodiceFiscale($fiscalCode)) {
                $result->error('00306', $party.'/CodiceFiscale', ['value' => $fiscalCode]);
            }
        }
    }

    private function checkLines(DOMXPath $xp, ValidationResult $result): void
    {
        foreach ($xp->query('//*[local-name()="DettaglioLinee"]') ?: [] as $line) {
            if (! $line instanceof DOMElement) {
                continue;
            }

            $n = $this->childText($line, 'NumeroLinea');
            $path = 'DettaglioLinee['.$n.']';
            $unit = (float) $this->childText($line, 'PrezzoUnitario');
            $qty = $this->childText($line, 'Quantita') === '' ? 1.0 : (float) $this->childText($line, 'Quantita');
            $total = (float) $this->childText($line, 'PrezzoTotale');
            $expected = $unit * $qty;

            foreach ($line->getElementsByTagName('ScontoMaggiorazione') as $adjustment) {
                $expected = $this->applyAdjustment($expected, $adjustment);
            }

            if (abs($expected - $total) > max(self::Tolerance, 0.0001 * abs($expected))) {
                $result->error('00423', $path.'/PrezzoTotale', ['expected' => round($expected, 2), 'actual' => $total]);
            }

            $this->checkRateAndNature((float) $this->childText($line, 'AliquotaIVA'), $this->childText($line, 'Natura'), $path, $result);
        }
    }

    private function applyAdjustment(float $amount, DOMElement $adjustment): float
    {
        $sign = $this->childText($adjustment, 'Tipo') === 'MG' ? 1 : -1;
        $percentage = $this->childText($adjustment, 'Percentuale');
        $value = $percentage !== '' ? $amount * (float) $percentage / 100 : (float) $this->childText($adjustment, 'Importo');

        return $amount + $sign * $value;
    }

    private function checkRateAndNature(float $rate, string $nature, string $path, ValidationResult $result): void
    {
        if ($rate === 0.0 && ! in_array($nature, self::NaturePrefixes, true)) {
            $result->error('00400', $path.'/Natura', ['value' => $nature]);
        }

        if ($rate > 0.0 && $nature !== '') {
            $result->error('00401', $path.'/Natura', ['value' => $nature, 'rate' => $rate]);
        }
    }

    private function checkSummary(DOMXPath $xp, ValidationResult $result): void
    {
        foreach ($xp->query('//*[local-name()="DatiBeniServizi"]') ?: [] as $goods) {
            $groups = [];

            foreach ($xp->query('./*[local-name()="DettaglioLinee"]', $goods) ?: [] as $line) {
                if ($line instanceof DOMElement) {
                    $key = $this->rateKey((float) $this->childText($line, 'AliquotaIVA'), $this->childText($line, 'Natura'));
                    $groups[$key] = ($groups[$key] ?? 0.0) + (float) $this->childText($line, 'PrezzoTotale');
                }
            }

            foreach ($xp->query('./*[local-name()="DatiRiepilogo"]', $goods) ?: [] as $summary) {
                if (! $summary instanceof DOMElement) {
                    continue;
                }

                $rate = (float) $this->childText($summary, 'AliquotaIVA');
                $nature = $this->childText($summary, 'Natura');
                $taxable = (float) $this->childText($summary, 'ImponibileImporto');
                $tax = (float) $this->childText($summary, 'Imposta');
                $rounding = (float) $this->childText($summary, 'Arrotondamento');
                $path = 'DatiRiepilogo['.$this->rateKey($rate, $nature).']';

                $this->checkRateAndNature($rate, $nature, $path, $result);

                if ($rate === 0.0 && $this->childText($summary, 'RiferimentoNormativo') === '') {
                    $result->warning('00430', $path.'/RiferimentoNormativo');
                }

                $lines = $groups[$this->rateKey($rate, $nature)] ?? 0.0;

                if (abs($lines - $taxable) > self::Tolerance && abs($lines - $taxable - $rounding) > self::Tolerance) {
                    $result->error('00422', $path.'/ImponibileImporto', ['expected' => round($lines, 2), 'actual' => $taxable]);
                }

                $expectedTax = round($taxable * $rate / 100, 2);

                if (abs($expectedTax - $tax) > self::Tolerance) {
                    $result->error('00424', $path.'/Imposta', ['expected' => $expectedTax, 'actual' => $tax]);
                }
            }
        }
    }

    private function checkDocumentRules(DOMXPath $xp, string $format, ValidationResult $result): void
    {
        $type = $this->text($xp, '//*[local-name()="TipoDocumento"]');

        if ($format === 'FPA12') {
            foreach ($xp->query('//*[local-name()="DatiRiepilogo"]') ?: [] as $summary) {
                if ($summary instanceof DOMElement && (float) $this->childText($summary, 'Imposta') > 0 && $this->childText($summary, 'EsigibilitaIVA') !== 'S') {
                    $result->warning('SPLIT_PAYMENT_EXPECTED', 'DatiRiepilogo/EsigibilitaIVA');
                }
            }
        }

        if (in_array($type, self::SelfInvoiceTypes, true)) {
            if ($this->text($xp, '//*[local-name()="SoggettoEmittente"]') !== 'CC') {
                $result->error('SELF_INVOICE_ISSUER_REQUIRED', 'SoggettoEmittente');
            }

            $seller = $this->text($xp, '//*[local-name()="CedentePrestatore"]//*[local-name()="IdCodice"]');
            $buyer = $this->text($xp, '//*[local-name()="CessionarioCommittente"]//*[local-name()="IdCodice"]');

            if ($seller !== '' && $seller === $buyer) {
                $result->error('SELF_INVOICE_PARTIES_IDENTICAL', 'CedentePrestatore');
            }
        }

        if ($type === 'TD24' && $xp->query('//*[local-name()="DatiDDT"]')->length === 0) {
            $result->error('DEFERRED_INVOICE_DDT_REQUIRED', 'DatiGenerali/DatiDDT');
        }
    }

    private function checkDuplicate(DOMXPath $xp, string $tenantId, ?string $ignoreId, ValidationResult $result): void
    {
        $number = $this->text($xp, '//*[local-name()="DatiGeneraliDocumento"]/*[local-name()="Numero"]');
        $date = $this->text($xp, '//*[local-name()="DatiGeneraliDocumento"]/*[local-name()="Data"]');

        if ($number === '' || $date === '') {
            return;
        }

        $duplicate = FpaDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('direction', FpaDirection::Outbound->value)
            ->where('document_number', $number)
            ->whereDate('document_date', $date)
            ->whereIn('status', [
                FpaStatus::Delivered->value, FpaStatus::Undelivered->value, FpaStatus::DeadlinePassed->value,
                FpaStatus::TransmittedAttested->value, FpaStatus::PaAccepted->value,
            ])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($duplicate) {
            $result->error('00404', 'DatiGeneraliDocumento', ['number' => $number, 'date' => $date]);
        }
    }

    private function rateKey(float $rate, string $nature): string
    {
        return number_format($rate, 2, '.', '').'|'.$nature;
    }

    private function text(DOMXPath $xp, string $query): string
    {
        $node = $xp->query($query)->item(0);

        return $node !== null ? trim($node->textContent) : '';
    }

    private function childText(DOMElement $parent, string $name): string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return trim($child->textContent);
            }
        }

        return '';
    }
}
