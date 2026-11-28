<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Fiscal;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Transliterator;

/**
 * Italy: totals have two decimals rounded half up, VAT-free lines carry the natura (N1 to N7) and its legal reference,
 * the document prints Art. 10 DPR 633/72 exemptions, split payment, reverse charge (Art. 17), virtual stamp duty and the
 * GDPR Art. 12 notice. An invoice issued in a foreign language is accompanied by an Italian translation (Art. 21 DPR
 * 633/72). FatturaPA files are Latin-1: anything outside it is transliterated.
 */
final class ItalianFiscalLocalization implements FiscalLocalizationStrategy
{
    private const array SYMBOL_MAP = [
        '€' => 'EUR', '‘' => "'", '’' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...', '•' => '-', 'œ' => 'oe', 'Œ' => 'OE', '™' => '(TM)', "\u{00A0}" => ' ',
    ];

    public function country(): string
    {
        return 'IT';
    }

    public function roundingIncrement(): string
    {
        return '0.01';
    }

    public function roundDocumentTotal(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
    }

    public function statutoryNoticeKeys(FiscalNoticeContext $context): array
    {
        $keys = [];

        if ($context->naturaCode !== null && $context->naturaCode !== '') {
            $keys[] = $this->naturaKey($context->naturaCode);
        }

        if ($context->exemptArt10) {
            $keys[] = 'statutory.it.art10_dpr633';
        }

        if ($context->splitPayment) {
            $keys[] = 'statutory.it.split_payment';
        }

        if ($context->reverseCharge) {
            $keys[] = 'statutory.it.reverse_charge';
        }

        if ($context->virtualStamp) {
            $keys[] = 'statutory.it.virtual_stamp';
        }

        if ($context->privacyNotice) {
            $keys[] = 'statutory.it.gdpr_art12';
        }

        if ($this->requiresItalianTranslation($context->documentLocale)) {
            $keys[] = 'statutory.it.translation_notice';
        }

        return $keys;
    }

    /**
     * The key of the legal text of a natura code (N2.1 gives the text of N2).
     */
    public function naturaKey(string $naturaCode): string
    {
        $parent = strtolower(explode('.', trim($naturaCode))[0]);

        return 'statutory.it.natura.'.$parent;
    }

    /**
     * A document printed in a language other than Italian needs the Italian translation print (Art. 21 DPR 633/72).
     */
    public function requiresItalianTranslation(string $documentLocale): bool
    {
        return ! str_starts_with(strtolower($documentLocale), 'it');
    }

    /**
     * Text safe for a FatturaPA XML file (Latin-1): characters beyond ISO-8859-1 are replaced by their closest ASCII form.
     */
    public function transliterateToLatin1(string $text): string
    {
        $transliterator = Transliterator::create('Any-Latin; Latin-ASCII');
        $result = '';

        foreach (mb_str_split($text) as $character) {
            if (isset(self::SYMBOL_MAP[$character])) {
                $result .= self::SYMBOL_MAP[$character];
            } elseif (mb_ord($character) <= 0xFF) {
                $result .= $character;
            } else {
                $ascii = $transliterator?->transliterate($character);
                $result .= ($ascii === false || $ascii === null || $ascii === '' || mb_ord($ascii) > 0xFF) ? '?' : $ascii;
            }
        }

        return $result;
    }
}
