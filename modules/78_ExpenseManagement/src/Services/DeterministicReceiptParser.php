<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Modules\ExpenseManagement\Contracts\ReceiptOcrProviderInterface;
use Modules\ExpenseManagement\Domain\ValueObjects\OcrResult;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * The default OCR provider: a deterministic text parser that works offline. It reads the text of plain-text and
 * text-layer PDF receipts, or the text a mobile client already recognised on the device, and finds merchant, date,
 * currency, total, VAT and item lines. An image without client text yields an empty result with a low confidence, never
 * an invented one. An OCR engine replaces it through the container binding of the interface.
 */
final class DeterministicReceiptParser implements ReceiptOcrProviderInterface
{
    public const string Name = 'deterministic';

    public function extract(string $contents, string $mimeType, string $fileName, ?string $clientText = null): OcrResult
    {
        $text = $this->textOf($contents, $mimeType, $clientText);

        if (trim($text) === '') {
            return new OcrResult(self::Name, 0.0);
        }

        $merchant = $this->merchant($text);
        $date = $this->date($text);
        $currency = $this->currency($text);
        $total = $this->total($text);
        $vatAmount = $this->vatAmount($text, $total);
        $vatRate = $this->vatRate($text);

        $found = count(array_filter([$merchant, $date, $currency, $total, $vatAmount ?? $vatRate]));
        $confidence = min(0.95, $found / 5 * 0.9 + ($clientText !== null ? 0.05 : 0.0));

        return new OcrResult(self::Name, $confidence, $merchant, $date, $currency, $total, $vatAmount, $vatRate, $this->lines($text));
    }

    private function textOf(string $contents, string $mimeType, ?string $clientText): string
    {
        if ($clientText !== null && trim($clientText) !== '') {
            return $clientText;
        }

        if (str_starts_with($mimeType, 'text/')) {
            return $contents;
        }

        if ($mimeType === 'application/pdf') {
            preg_match_all('/\(([^()\\\\]{2,200})\)\s*T[jJ]/', $contents, $matches);

            return implode("\n", $matches[1]);
        }

        return '';
    }

    private function merchant(string $text): ?string
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if (strlen($line) >= 3 && preg_match('/[A-Za-zÀ-ÿ]{3}/u', $line) === 1 && preg_match('/^(?:scontrino|ricevuta|receipt|quittung|beleg|ticket|fattura)/i', $line) !== 1) {
                return mb_substr($line, 0, 200);
            }
        }

        return null;
    }

    private function date(string $text): ?string
    {
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        if (preg_match('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})\b/', $text, $m) === 1) {
            $year = strlen($m[3]) === 2 ? '20'.$m[3] : $m[3];

            if (checkdate((int) $m[2], (int) $m[1], (int) $year)) {
                return sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
            }
        }

        return null;
    }

    private function currency(string $text): ?string
    {
        return match (true) {
            preg_match('/\bCHF\b|\bFr\.\s?\d/i', $text) === 1 => 'CHF',
            preg_match('/\bEUR\b|€/u', $text) === 1 => 'EUR',
            preg_match('/\bUSD\b|\$/', $text) === 1 => 'USD',
            preg_match('/\bGBP\b|£/u', $text) === 1 => 'GBP',
            default => null,
        };
    }

    private function total(string $text): ?string
    {
        if (preg_match_all('/(?:totale|total|summe|gesamt|montant|importo)[^\d\n]{0,25}(\d[\d.,\']*[.,]\d{2})\b/i', $text, $matches) > 0) {
            $amounts = array_map($this->amount(...), $matches[1]);
            usort($amounts, static fn (string $a, string $b): int => bccomp($b, $a, 2));

            return $amounts[0];
        }

        if (preg_match_all('/(\d[\d.,\']*[.,]\d{2})\b/', $text, $matches) > 0) {
            $amounts = array_map($this->amount(...), $matches[1]);
            usort($amounts, static fn (string $a, string $b): int => bccomp($b, $a, 2));

            return $amounts[0];
        }

        return null;
    }

    private function vatAmount(string $text, ?string $total): ?string
    {
        if (preg_match('/(?:iva|mwst|tva|vat|imposta)[^\n]{0,20}?(\d[\d.,\']*[.,]\d{2})\b(?!\s*%)/i', $text, $m) === 1) {
            $amount = $this->amount($m[1]);

            return $total === null || bccomp($amount, $total, 2) < 0 ? $amount : null;
        }

        return null;
    }

    private function vatRate(string $text): ?string
    {
        return preg_match('/(?:iva|mwst|tva|vat)[^\d\n]{0,10}(\d{1,2}(?:[.,]\d{1,2})?)\s*%/i', $text, $m) === 1
            ? Decimal::normalize(str_replace(',', '.', $m[1]), 4)
            : null;
    }

    /**
     * @return list<array{description: string, amount: string}>
     */
    private function lines(string $text): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*(.{3,60}?)\s{1,}(\d[\d.,\']*[.,]\d{2})\s*$/u', $line, $m) === 1 && preg_match('/total|totale|iva|vat|mwst|tva|summe|resto|change/i', $m[1]) !== 1) {
                $lines[] = ['description' => trim($m[1]), 'amount' => $this->amount($m[2])];
            }
        }

        return array_slice($lines, 0, 30);
    }

    private function amount(string $raw): string
    {
        $raw = str_replace("'", '', $raw);
        $comma = strrpos($raw, ',');
        $dot = strrpos($raw, '.');
        $decimalAt = max($comma === false ? -1 : $comma, $dot === false ? -1 : $dot);
        $integer = preg_replace('/[.,]/', '', substr($raw, 0, $decimalAt)) ?? '0';

        return Decimal::normalize($integer.'.'.substr($raw, $decimalAt + 1));
    }
}
