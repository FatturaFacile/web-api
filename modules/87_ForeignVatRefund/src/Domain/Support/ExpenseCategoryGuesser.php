<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Domain\Support;

use Modules\ForeignVatRefund\Domain\Enums\ExpenseCategory;

/**
 * Proposes the EU expense code (1-10) of a document line from its description, in the four languages of the application.
 * A proposal only: the accountant confirms or changes it in the ledger; without a match the code is 10 (other).
 */
final class ExpenseCategoryGuesser
{
    /** @var array<int, list<string>> */
    private const array KEYWORDS = [
        9 => ['luxury', 'entertainment', 'casino', 'intrattenimento', 'rappresentanza', 'unterhaltung', 'divertissement', 'spettacol'],
        1 => ['fuel', 'petrol', 'diesel', 'carburant', 'benzin', 'gasolio', 'treibstoff', 'kraftstoff', 'essence', 'gazole', 'tankstelle'],
        2 => ['car hire', 'rental car', 'car rental', 'noleggio', 'mietwagen', 'autovermietung', 'location de voiture', 'location voiture'],
        4 => ['toll', 'pedaggio', 'autostrad', 'maut', 'vignette', 'péage', 'peage', 'autobahn'],
        3 => ['repair', 'riparazione', 'reparatur', 'réparation', 'parking', 'parcheggio', 'parkplatz', 'servizio auto', 'garage', 'werkstatt'],
        6 => ['hotel', 'accommodation', 'alloggio', 'albergo', 'unterkunft', 'übernachtung', 'logement', 'hébergement', 'pernottamento', 'b&b'],
        7 => ['restaurant', 'ristorante', 'meal', 'pranzo', 'cena', 'catering', 'verpflegung', 'bewirtung', 'repas', 'trattoria', 'food', 'drink'],
        8 => ['fair', 'exhibition', 'trade show', 'fiera', 'messe', 'ausstellung', 'salon', 'stand', 'expo'],
        5 => ['flight', 'train', 'ticket', 'taxi', 'volo', 'treno', 'biglietto', 'flug', 'bahn', 'billet', 'vol ', 'travel'],
    ];

    public static function guess(?string $description): int
    {
        $text = mb_strtolower((string) $description);

        if ($text === '') {
            return ExpenseCategory::Other->value;
        }

        foreach (self::KEYWORDS as $code => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    return $code;
                }
            }
        }

        return ExpenseCategory::Other->value;
    }
}
