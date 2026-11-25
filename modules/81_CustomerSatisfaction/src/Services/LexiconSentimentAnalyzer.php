<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use Modules\CustomerSatisfaction\Contracts\SentimentAnalyzerInterface;

/**
 * Offline lexicon sentiment (it, en, de, fr) with theme detection. Deterministic, so reports are reproducible; the Mod 301 AI
 * gateway can replace it by rebinding {@see SentimentAnalyzerInterface}.
 */
final class LexiconSentimentAnalyzer implements SentimentAnalyzerInterface
{
    private const array Positive = [
        'ottimo', 'eccellente', 'bravo', 'veloce', 'rapido', 'gentile', 'professionale', 'perfetto', 'soddisfatto', 'grazie', 'cortese', 'preciso',
        'great', 'excellent', 'good', 'fast', 'quick', 'helpful', 'friendly', 'professional', 'perfect', 'satisfied', 'thanks', 'smooth', 'easy',
        'ausgezeichnet', 'sehr gut', 'gut', 'schnell', 'freundlich', 'hilfsbereit', 'zufrieden', 'danke', 'einfach', 'perfekt',
        'excellent', 'très bien', 'bien', 'rapide', 'aimable', 'serviable', 'satisfait', 'merci', 'parfait', 'simple',
    ];

    private const array Negative = [
        'pessimo', 'scarso', 'lento', 'ritardo', 'problema', 'insoddisfatto', 'maleducato', 'errore', 'sbagliato', 'caro', 'deluso', 'disastro', 'rotto',
        'bad', 'poor', 'slow', 'late', 'delay', 'problem', 'unsatisfied', 'rude', 'error', 'wrong', 'expensive', 'disappointed', 'disaster', 'broken', 'never',
        'schlecht', 'langsam', 'verspätet', 'verspaetet', 'problem', 'unzufrieden', 'unfreundlich', 'fehler', 'falsch', 'teuer', 'enttäuscht', 'enttaeuscht', 'kaputt',
        'mauvais', 'lent', 'retard', 'problème', 'probleme', 'insatisfait', 'impoli', 'erreur', 'faux', 'cher', 'déçu', 'decu', 'cassé', 'casse',
    ];

    /** @var array<string, list<string>> */
    private const array Topics = [
        'delivery' => ['consegna', 'spedizione', 'delivery', 'shipping', 'lieferung', 'versand', 'livraison', 'expédition'],
        'price' => ['prezzo', 'costo', 'caro', 'price', 'cost', 'expensive', 'preis', 'kosten', 'teuer', 'prix', 'coût', 'cher'],
        'support' => ['assistenza', 'supporto', 'support', 'help', 'hilfe', 'service', 'aide'],
        'quality' => ['qualità', 'qualita', 'difetto', 'rotto', 'quality', 'defect', 'broken', 'qualität', 'qualitaet', 'kaputt', 'qualité', 'cassé'],
        'speed' => ['veloce', 'lento', 'rapido', 'ritardo', 'fast', 'slow', 'late', 'delay', 'schnell', 'langsam', 'verspätet', 'rapide', 'lent', 'retard'],
        'communication' => ['comunicazione', 'risposta', 'communication', 'response', 'reply', 'kommunikation', 'antwort', 'réponse', 'reponse'],
        'staff' => ['tecnico', 'personale', 'operatore', 'technician', 'staff', 'agent', 'techniker', 'mitarbeiter', 'technicien', 'personnel'],
    ];

    public function analyze(string $text, string $locale): array
    {
        $normalized = mb_strtolower($text);
        $words = preg_split('/[^\p{L}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $positive = 0;
        $negative = 0;

        foreach ($words as $word) {
            $positive += in_array($word, self::Positive, true) ? 1 : 0;
            $negative += in_array($word, self::Negative, true) ? 1 : 0;
        }

        $positive += substr_count($normalized, 'sehr gut') + substr_count($normalized, 'très bien');

        $hits = $positive + $negative;
        $score = $hits === 0 ? 0.0 : round(($positive - $negative) / $hits, 2);
        $label = match (true) {
            $score >= 0.25 => 'positive',
            $score <= -0.25 => 'negative',
            default => 'neutral',
        };

        $topics = [];
        foreach (self::Topics as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (in_array($keyword, $words, true)) {
                    $topics[] = $topic;
                    break;
                }
            }
        }

        return ['score' => $score, 'label' => $label, 'topics' => $topics];
    }
}
