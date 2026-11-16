<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Extracts expense fields from raw OCR text produced on the device
 * (Italian / Swiss receipts). Works offline-first: the app OCRs locally and
 * calls this only when online, or mirrors the same rules on-device.
 */
final class ReceiptTextParser
{
    /**
     * @return array{vendor: ?string, date: ?string, total: ?float, currency: string, vat_amount: ?float, vat_rate: ?float, vat_number: ?string, confidence: float}
     */
    public function parse(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), fn ($l) => $l !== ''));

        $total = $this->total($lines);
        $date = $this->date($text);
        $vat = $this->vat($lines);
        $vatNumber = $this->vatNumber($text);
        $vendor = $this->vendor($lines);

        $found = count(array_filter([$total, $date, $vendor, $vatNumber], fn ($v) => $v !== null));

        return [
            'vendor' => $vendor,
            'date' => $date,
            'total' => $total,
            'currency' => preg_match('/\bCHF\b|\bFr\.\s?\d/i', $text) ? 'CHF' : 'EUR',
            'vat_amount' => $vat['amount'],
            'vat_rate' => $vat['rate'],
            'vat_number' => $vatNumber,
            'confidence' => round($found / 4, 2),
        ];
    }

    /** @param list<string> $lines */
    private function total(array $lines): ?float
    {
        $keywords = '/(totale\s+complessivo|totale\s+euro|importo\s+pagato|totale|total|gesamtbetrag|gesamt|summe|montant|a\s+payer)/i';
        $best = null;

        foreach ($lines as $line) {
            if (preg_match($keywords, $line) && ! preg_match('/subtotal|sub-total|imponibile|iva|mwst|tva/i', $line)) {
                $amount = $this->lastAmount($line);
                if ($amount !== null) {
                    $best = $amount; // last matching line wins (grand total comes last)
                }
            }
        }

        if ($best !== null) {
            return $best;
        }

        $amounts = [];
        foreach ($lines as $line) {
            $a = $this->lastAmount($line);
            if ($a !== null) {
                $amounts[] = $a;
            }
        }

        return $amounts === [] ? null : max($amounts);
    }

    private function lastAmount(string $line): ?float
    {
        if (! preg_match_all('/(?<![\d.,])(\d{1,3}(?:[.\']\d{3})*[,.]\d{2}|\d+[,.]\d{2})(?![\d])/', $line, $m)) {
            return null;
        }

        return $this->toFloat((string) end($m[1]));
    }

    private function toFloat(string $raw): float
    {
        $raw = str_replace("'", '', $raw);
        $lastComma = strrpos($raw, ',');
        $lastDot = strrpos($raw, '.');
        $decimalPos = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);

        $int = preg_replace('/[.,]/', '', substr($raw, 0, $decimalPos)) ?? '';

        return (float) ($int.'.'.substr($raw, $decimalPos + 1));
    }

    private function date(string $text): ?string
    {
        if (preg_match('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\b/', $text, $m)) {
            $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            try {
                return CarbonImmutable::create($year, (int) $m[2], (int) $m[1])->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $text, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @return array{amount: ?float, rate: ?float}
     */
    private function vat(array $lines): array
    {
        foreach ($lines as $line) {
            if (preg_match('/\b(iva|mwst|tva|vat)\b/i', $line)) {
                $rate = preg_match('/(\d{1,2}(?:[.,]\d{1,2})?)\s?%/', $line, $r) ? (float) str_replace(',', '.', $r[1]) : null;
                $amount = $this->lastAmount($line);
                if ($amount !== null || $rate !== null) {
                    return ['amount' => $amount, 'rate' => $rate];
                }
            }
        }

        return ['amount' => null, 'rate' => null];
    }

    private function vatNumber(string $text): ?string
    {
        if (preg_match('/CHE[-\s]?\d{3}\.?\d{3}\.?\d{3}(?:\s?(?:MWST|TVA|IVA))?/i', $text, $m)) {
            return strtoupper(trim($m[0]));
        }

        if (preg_match('/(?:p\.?\s?iva|partita\s+iva|vat)[:\s]*([A-Z]{2})?\s?(\d{11})\b/i', $text, $m)) {
            return ($m[1] !== '' ? strtoupper($m[1]) : 'IT').$m[2];
        }

        return null;
    }

    /** @param list<string> $lines */
    private function vendor(array $lines): ?string
    {
        foreach (array_slice($lines, 0, 5) as $line) {
            if (preg_match('/[A-Za-zÀ-ÿ]{3,}/u', $line) && ! preg_match('/^(scontrino|ricevuta|receipt|quittung|\d)/i', $line)) {
                return mb_substr($line, 0, 120);
            }
        }

        return null;
    }
}
