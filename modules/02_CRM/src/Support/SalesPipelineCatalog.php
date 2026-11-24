<?php

declare(strict_types=1);

namespace Modules\CRM\Support;

/**
 * Reference catalog of the sales pipeline (pipelines, stages, loss reasons) with its per-locale labels
 * (it-CH, de-CH, fr-CH, en, it-IT). Used by the idempotent seeder and by the "install standard pipeline" action; the
 * frontend only renders what the API serves from these rows.
 */
final class SalesPipelineCatalog
{
    /**
     * @return array<string, string>
     */
    public static function labels(string $it, string $de, string $fr, string $en): array
    {
        return ['it-CH' => $it, 'de-CH' => $de, 'fr-CH' => $fr, 'en' => $en, 'it-IT' => $it];
    }

    /**
     * Pipelines seeded for every tenant. The first one is the default.
     *
     * @return list<array{code: string, name: string, business_line: string|null, market: string|null, is_default: bool, sequence: int, stage_prefix: string, translations: array<string, string>}>
     */
    public static function pipelines(): array
    {
        return [
            [
                'code' => 'default', 'name' => 'Core business — Switzerland', 'business_line' => 'core', 'market' => 'CH', 'is_default' => true, 'sequence' => 10, 'stage_prefix' => '',
                'translations' => self::labels('Attività principale — Svizzera', 'Kerngeschäft — Schweiz', 'Activité principale — Suisse', 'Core business — Switzerland'),
            ],
            [
                'code' => 'italy', 'name' => 'Italian market', 'business_line' => 'core', 'market' => 'IT', 'is_default' => false, 'sequence' => 20, 'stage_prefix' => 'it_',
                'translations' => self::labels('Mercato Italia', 'Italienischer Markt', 'Marché italien', 'Italian market'),
            ],
        ];
    }

    /**
     * Stages of a pipeline, in order. Codes of the default pipeline keep the historical stage codes.
     *
     * @return list<array{code: string, name: string, sequence: int, probability: float, is_won: bool, is_lost: bool, forecast_category: string, stale_days: int, translations: array<string, string>}>
     */
    public static function stages(): array
    {
        return [
            ['code' => 'discovery', 'name' => 'Qualification', 'sequence' => 10, 'probability' => 10.0, 'is_won' => false, 'is_lost' => false, 'forecast_category' => 'pipeline', 'stale_days' => 21, 'translations' => self::labels('Qualificazione', 'Qualifizierung', 'Qualification', 'Qualification')],
            ['code' => 'qualification', 'name' => 'Solution Presentation', 'sequence' => 20, 'probability' => 25.0, 'is_won' => false, 'is_lost' => false, 'forecast_category' => 'pipeline', 'stale_days' => 21, 'translations' => self::labels('Presentazione Soluzione', 'Lösungspräsentation', 'Présentation de la solution', 'Solution Presentation')],
            ['code' => 'proposal', 'name' => 'Quote Issued', 'sequence' => 30, 'probability' => 50.0, 'is_won' => false, 'is_lost' => false, 'forecast_category' => 'best_case', 'stale_days' => 30, 'translations' => self::labels('Offerta Emessa', 'Angebot erstellt', 'Offre émise', 'Quote Issued')],
            ['code' => 'negotiation', 'name' => 'Negotiation', 'sequence' => 40, 'probability' => 75.0, 'is_won' => false, 'is_lost' => false, 'forecast_category' => 'commit', 'stale_days' => 15, 'translations' => self::labels('Negoziazione', 'Verhandlung', 'Négociation', 'Negotiation')],
            ['code' => 'closed_won', 'name' => 'Closed Won', 'sequence' => 90, 'probability' => 100.0, 'is_won' => true, 'is_lost' => false, 'forecast_category' => 'closed', 'stale_days' => 0, 'translations' => self::labels('Chiusa Vinta', 'Abgeschlossen — gewonnen', 'Clôturée gagnée', 'Closed Won')],
            ['code' => 'closed_lost', 'name' => 'Closed Lost', 'sequence' => 100, 'probability' => 0.0, 'is_won' => false, 'is_lost' => true, 'forecast_category' => 'omitted', 'stale_days' => 0, 'translations' => self::labels('Chiusa Persa', 'Abgeschlossen — verloren', 'Clôturée perdue', 'Closed Lost')],
        ];
    }

    /**
     * @return list<array{code: string, name: string, sequence: int, requires_competitor: bool, translations: array<string, string>}>
     */
    public static function lossReasons(): array
    {
        return [
            ['code' => 'price', 'name' => 'Price', 'sequence' => 10, 'requires_competitor' => false, 'translations' => self::labels('Prezzo', 'Preis', 'Prix', 'Price')],
            ['code' => 'specific_competitor', 'name' => 'Specific competitor', 'sequence' => 20, 'requires_competitor' => true, 'translations' => self::labels('Concorrente specifico', 'Bestimmter Wettbewerber', 'Concurrent spécifique', 'Specific competitor')],
            ['code' => 'missing_features', 'name' => 'Missing features', 'sequence' => 30, 'requires_competitor' => false, 'translations' => self::labels('Mancanza funzionalità', 'Fehlende Funktionen', 'Fonctionnalités manquantes', 'Missing features')],
            ['code' => 'project_frozen', 'name' => 'Project frozen', 'sequence' => 40, 'requires_competitor' => false, 'translations' => self::labels('Progetto congelato', 'Projekt eingefroren', 'Projet gelé', 'Project frozen')],
            ['code' => 'no_decision', 'name' => 'No decision', 'sequence' => 50, 'requires_competitor' => false, 'translations' => self::labels('Nessuna decisione', 'Keine Entscheidung', 'Aucune décision', 'No decision')],
            ['code' => 'other', 'name' => 'Other', 'sequence' => 90, 'requires_competitor' => false, 'translations' => self::labels('Altro', 'Sonstiges', 'Autre', 'Other')],
        ];
    }
}
