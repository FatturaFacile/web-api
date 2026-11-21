<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

/**
 * Deterministic masking layer in front of every hosted model: IBANs (checksum verified), Swiss AHV/AVS numbers, Italian
 * Codice Fiscale, VAT numbers, emails, phone numbers and person names are replaced by stable tokens such as [[IBAN_1]].
 * The same value always gets the same token inside one text, and the answer of the model is re-hydrated locally, so the
 * hosted provider never sees the originals. Names are found from salutations and titles plus the names the caller knows.
 */
final class PiiMaskingService
{
    private const string Name = "\\p{Lu}[\\p{L}'’-]+(?:\\s+\\p{Lu}[\\p{L}'’-]+){0,2}";

    /**
     * @param  list<string>  $knownNames  names the caller knows to be personal (contacts of the document)
     * @return array{text: string, map: array<string, string>}
     */
    public function mask(string $text, array $knownNames = []): array
    {
        /** @var array<string, string> $map token => original */
        $map = [];
        /** @var array<string, string> $reverse original => token */
        $reverse = [];
        /** @var array<string, int> $counters */
        $counters = [];

        $replace = static function (string $type, string $original) use (&$map, &$reverse, &$counters): string {
            $key = $type.'|'.mb_strtolower($original);

            if (isset($reverse[$key])) {
                return $reverse[$key];
            }

            $counters[$type] = ($counters[$type] ?? 0) + 1;
            $token = '[['.$type.'_'.$counters[$type].']]';
            $map[$token] = $original;

            return $reverse[$key] = $token;
        };

        $text = (string) preg_replace_callback('/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,7}(?:[ ]?[A-Z0-9]{1,4})?\b/', fn (array $match): string => $this->isIban($match[0]) ? $replace('IBAN', $match[0]) : $match[0], $text);
        $text = (string) preg_replace_callback('/\b756[.\s]?\d{4}[.\s]?\d{4}[.\s]?\d{2}\b/', static fn (array $match): string => $replace('AHV', $match[0]), $text);
        $text = (string) preg_replace_callback('/\b[A-Z]{6}\d{2}[A-EHLMPR-T]\d{2}[A-Z]\d{3}[A-Z]\b/i', static fn (array $match): string => $replace('CF', $match[0]), $text);
        $text = (string) preg_replace_callback('/\bCHE[-\s]?\d{3}\.?\d{3}\.?\d{3}(?:\s?(?:MWST|TVA|IVA))?\b|\bIT\d{11}\b/', static fn (array $match): string => $replace('VAT', $match[0]), $text);
        $text = (string) preg_replace_callback('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', static fn (array $match): string => $replace('EMAIL', $match[0]), $text);
        $text = (string) preg_replace_callback('/(?<![\w.])\+\d{1,3}[\s().-]?\d(?:[\s().-]?\d){6,12}\b|(?<![\w.])0\d{2}[\s.-]?\d{3}[\s.-]?\d{2}[\s.-]?\d{2}\b/', static fn (array $match): string => $replace('PHONE', $match[0]), $text);

        foreach ($knownNames as $name) {
            $name = trim($name);

            if (mb_strlen($name) >= 3) {
                $text = (string) preg_replace_callback('/(?<![\p{L}\d])'.preg_quote($name, '/').'(?![\p{L}\d])/iu', static fn (array $match): string => $replace('NAME', $match[0]), $text);
            }
        }

        $salutation = '/\b(?:Dear|Mr\.?|Mrs\.?|Ms\.?|Dr\.?|Sig\.?ra|Sig\.?|Gentile|Egregio|Spett\.le|Dott\.?|Dott\.ssa|Avv\.?|Herr|Frau|Sehr geehrte[r]?|Monsieur|Madame|Cher|Chère|Bonjour|Hallo|Ciao|Regards,?|Best regards,?|Cordiali saluti,?|Freundliche Grüsse,?|Mit freundlichen Grüssen,?|Cordialement,?)\s+('.self::Name.')/u';
        $text = (string) preg_replace_callback($salutation, static fn (array $match): string => str_replace($match[1], $replace('NAME', $match[1]), $match[0]), $text);

        return ['text' => $text, 'map' => $map];
    }

    /**
     * Puts the originals back into an answer of the model.
     *
     * @param  array<string, string>  $map
     */
    public function rehydrate(string $text, array $map): string
    {
        return $map === [] ? $text : strtr($text, $map);
    }

    private function isIban(string $candidate): bool
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', $candidate));

        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;

        foreach (str_split($numeric, 7) as $block) {
            $remainder = (int) ($remainder.$block) % 97;
        }

        return $remainder === 1;
    }
}
