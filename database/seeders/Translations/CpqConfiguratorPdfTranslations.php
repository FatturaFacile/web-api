<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * Labels of the technical-commercial proposal of the CPQ configurator (`PDF.CPQ_PROPOSAL.*`) in it, en, de, fr, read by
 * the proposal renderer in the language of the document. Seeded by CpqConfiguratorLocalizationSeeder.
 */
final class CpqConfiguratorPdfTranslations
{
    private const array Locales = ['it' => 0, 'en' => 1, 'de' => 2, 'fr' => 3];

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = self::Locales[$locale] ?? throw new InvalidArgumentException("Unsupported locale [{$locale}].");

        return array_map(static fn (array $texts): string => $texts[$index], self::definitions());
    }

    /**
     * Key => [it, en, de, fr].
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'PDF.CPQ_PROPOSAL.TITLE' => ['Proposta tecnico-commerciale', 'Technical and commercial proposal', 'Technisch-kommerzielles Angebot', 'Proposition technique et commerciale'],
            'PDF.CPQ_PROPOSAL.REFERENCE' => ['Codice articolo', 'Part number', 'Artikelnummer', 'Référence article'],
            'PDF.CPQ_PROPOSAL.CUSTOMER' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'PDF.CPQ_PROPOSAL.SPECIFICATION' => ['Specifica tecnica della variante', 'Technical specification of the variant', 'Technische Spezifikation der Variante', 'Spécification technique de la variante'],
            'PDF.CPQ_PROPOSAL.AUTO_INCLUDED' => ['incluso', 'included', 'eingeschlossen', 'inclus'],
            'PDF.CPQ_PROPOSAL.AUTO_INCLUDED_TITLE' => ['Dotazioni incluse automaticamente', 'Automatically included items', 'Automatisch eingeschlossene Ausstattung', 'Équipements inclus automatiquement'],
            'PDF.CPQ_PROPOSAL.SCOPE_OF_SUPPLY' => ['Ambito di fornitura', 'Scope of supply', 'Lieferumfang', 'Étendue de la fourniture'],
            'PDF.CPQ_PROPOSAL.CODE' => ['Codice', 'Code', 'Code', 'Code'],
            'PDF.CPQ_PROPOSAL.DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'PDF.CPQ_PROPOSAL.QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'PDF.CPQ_PROPOSAL.DIMENSIONS_INSTALLATION' => ['Dimensioni e installazione', 'Dimensions and installation', 'Abmessungen und Installation', 'Dimensions et installation'],
            'PDF.CPQ_PROPOSAL.COMMERCIAL' => ['Condizioni commerciali', 'Commercial conditions', 'Kommerzielle Bedingungen', 'Conditions commerciales'],
            'PDF.CPQ_PROPOSAL.LIST_PRICE' => ['Prezzo di listino', 'List price', 'Listenpreis', 'Prix catalogue'],
            'PDF.CPQ_PROPOSAL.DISCOUNT' => ['Sconto', 'Discount', 'Rabatt', 'Remise'],
            'PDF.CPQ_PROPOSAL.UNIT_PRICE' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'PDF.CPQ_PROPOSAL.TOTAL' => ['Totale', 'Total', 'Gesamt', 'Total'],
            'PDF.CPQ_PROPOSAL.LEAD_TIME' => ['Tempo di consegna', 'Lead time', 'Lieferzeit', 'Délai de livraison'],
            'PDF.CPQ_PROPOSAL.DAYS' => ['giorni', 'days', 'Tage', 'jours'],
            'PDF.CPQ_PROPOSAL.VALIDITY' => ['Validità dell’offerta', 'Offer validity', 'Gültigkeit des Angebots', 'Validité de l’offre'],
            'PDF.CPQ_PROPOSAL.INDUSTRIAL_BREAKDOWN' => ['Dettaglio industriale (riservato)', 'Industrial breakdown (restricted)', 'Industrielle Aufschlüsselung (vertraulich)', 'Détail industriel (confidentiel)'],
            'PDF.CPQ_PROPOSAL.BOM_COST' => ['Costo distinta base', 'BOM cost', 'Stücklistenkosten', 'Coût de la nomenclature'],
            'PDF.CPQ_PROPOSAL.ROUTING_COST' => ['Costo lavorazioni', 'Routing cost', 'Arbeitsgangkosten', 'Coût des opérations'],
            'PDF.CPQ_PROPOSAL.INDUSTRIAL_COST' => ['Costo industriale', 'Industrial cost', 'Industrielle Kosten', 'Coût industriel'],
            'PDF.CPQ_PROPOSAL.MARGIN' => ['Margine', 'Margin', 'Marge', 'Marge'],
            'PDF.CPQ_PROPOSAL.DELIVERY' => ['Consegna', 'Delivery', 'Lieferung', 'Livraison'],
            'PDF.CPQ_PROPOSAL.WARRANTY' => ['Garanzia', 'Warranty', 'Garantie', 'Garantie'],
            'PDF.CPQ_PROPOSAL.TERMS' => ['Condizioni generali', 'General terms', 'Allgemeine Bedingungen', 'Conditions générales'],
            'PDF.CPQ_PROPOSAL.HASH' => ['Impronta della configurazione (SHA-256)', 'Configuration fingerprint (SHA-256)', 'Konfigurations-Fingerabdruck (SHA-256)', 'Empreinte de la configuration (SHA-256)'],
        ];
    }
}
