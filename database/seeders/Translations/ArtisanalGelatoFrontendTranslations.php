<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the artisanal gelato, chocolaterie and cold pastry vertical (Mod 398): the balancing workbench, the lots and
 * HACCP console, the ganache studio, the showcase board and the allergen book. Each entry is [it, en, de, fr]; placeholders are
 * written {name}.
 */
final class ArtisanalGelatoFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.GELATO' => ['Gelateria e cioccolateria', 'Gelato & chocolaterie', 'Glacé & Chocolaterie', 'Glacier & chocolaterie'],
            'NAV.GELATO_WORKBENCH' => ['Bilanciamento ricette', 'Recipe balancing', 'Rezeptbilanzierung', 'Équilibrage des recettes'],
            'NAV.GELATO_LOTS' => ['Lotti e HACCP', 'Lots & HACCP', 'Chargen & HACCP', 'Lots & HACCP'],
            'NAV.GELATO_GANACHE' => ['Ganache e praline', 'Ganache & pralines', 'Ganache & Pralinen', 'Ganache & pralines'],
            'NAV.GELATO_SHOWCASE' => ['Vetrina e allergeni', 'Showcase & allergens', 'Vitrine & Allergene', 'Vitrine & allergènes'],
            'PAGE_TITLE.GELATO' => ['Gelateria, cioccolateria e pasticceria fredda', 'Gelato, chocolaterie and cold pastry', 'Glacé, Chocolaterie und Kaltpatisserie', 'Glacier, chocolaterie et pâtisserie froide'],

            'GELATO.WORKBENCH.TITLE' => ['Banco di bilanciamento', 'Balancing workbench', 'Bilanzierungs-Werkbank', 'Établi d\'équilibrage'],
            'GELATO.WORKBENCH.SUBTITLE' => ['Modifica i grammi: gli indici si ricalcolano in tempo reale', 'Edit the grams: the indexes recalculate in real time', 'Gramm ändern: die Kennzahlen werden live neu berechnet', 'Modifiez les grammes : les indices se recalculent en temps réel'],
            'GELATO.CATEGORY' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'GELATO.CATEGORY.DAIRY_CREAM' => ['Creme al latte', 'Dairy creams', 'Milchcremes', 'Crèmes au lait'],
            'GELATO.CATEGORY.FRUIT_SORBET' => ['Sorbetti di frutta', 'Fruit sorbets', 'Fruchtsorbets', 'Sorbets de fruits'],
            'GELATO.CATEGORY.CHOCOLATE_FAT_PASTE' => ['Cioccolato e paste grasse', 'Chocolate & fat pastes', 'Schokolade & Fettpasten', 'Chocolat & pâtes grasses'],
            'GELATO.CATEGORY.SAVORY_GASTRONOMIC' => ['Gelato gastronomico', 'Savory gelato', 'Herzhaftes Glacé', 'Glace gastronomique'],
            'GELATO.CATEGORY.SEMIFREDDO_COLD_PASTRY' => ['Semifreddo e pasticceria fredda', 'Semifreddo & cold pastry', 'Semifreddo & Kaltpatisserie', 'Semifreddo & pâtisserie froide'],
            'GELATO.CATEGORY.GANACHE_CHOCOLATE' => ['Ganache', 'Ganache', 'Ganache', 'Ganache'],
            'GELATO.INGREDIENT' => ['Ingrediente', 'Ingredient', 'Zutat', 'Ingrédient'],
            'GELATO.GRAMS' => ['Grammi', 'Grams', 'Gramm', 'Grammes'],
            'GELATO.ADD_LINE' => ['Aggiungi ingrediente', 'Add ingredient', 'Zutat hinzufügen', 'Ajouter un ingrédient'],
            'GELATO.BATCH_WEIGHT' => ['Peso del lotto (g)', 'Batch weight (g)', 'Chargengewicht (g)', 'Poids du lot (g)'],
            'GELATO.SCALE_BATCH' => ['Scala il lotto', 'Scale batch', 'Charge skalieren', 'Adapter le lot'],
            'GELATO.OVERRUN' => ['Overrun %', 'Overrun %', 'Overrun %', 'Foisonnement %'],
            'GELATO.GAUGE.POD' => ['POD (potere dolcificante)', 'POD (sweetening power)', 'POD (Süsskraft)', 'POD (pouvoir sucrant)'],
            'GELATO.GAUGE.PAC' => ['PAC (potere anticongelante)', 'PAC (anti-freezing power)', 'PAC (Gefrierschutzkraft)', 'PAC (pouvoir antigel)'],
            'GELATO.GAUGE.FAT' => ['Grassi totali %', 'Total fat %', 'Gesamtfett %', 'Graisses totales %'],
            'GELATO.GAUGE.SLNG' => ['SLNG %', 'SLNG %', 'SLNG %', 'SLNG %'],
            'GELATO.GAUGE.SOLIDS' => ['Solidi totali %', 'Total solids %', 'Gesamttrockenmasse %', 'Matière sèche totale %'],
            'GELATO.GAUGE.TEMP' => ['Temperatura di servizio ideale', 'Ideal serving temperature', 'Ideale Serviertemperatur', 'Température de service idéale'],
            'GELATO.STATUS.OPTIMAL' => ['Ottimale', 'Optimal', 'Optimal', 'Optimal'],
            'GELATO.STATUS.WARNING' => ['Attenzione', 'Warning', 'Warnung', 'Attention'],
            'GELATO.STATUS.OUT_OF_RANGE' => ['Fuori range', 'Out of range', 'Ausserhalb', 'Hors plage'],
            'GELATO.LACTOSE_RISK' => ['Rischio di cristallizzazione del lattosio (sabbiosità): SLNG oltre il limite {max} %', 'Lactose crystallization (sandiness) risk: SLNG above the {max} % limit', 'Laktose-Kristallisationsrisiko (Sandigkeit): SLNG über dem Grenzwert von {max} %', 'Risque de cristallisation du lactose (sablage) : SLNG au-dessus de la limite de {max} %'],
            'GELATO.FREEZING_CURVE' => ['Curva di congelamento', 'Freezing curve', 'Gefrierkurve', 'Courbe de congélation'],
            'GELATO.FROZEN_WATER' => ['Acqua congelata %', 'Frozen water %', 'Gefrorenes Wasser %', 'Eau gelée %'],
            'GELATO.COST' => ['Costo', 'Cost', 'Kosten', 'Coût'],
            'GELATO.COST_PER_KG' => ['Costo al kg', 'Cost per kg', 'Kosten pro kg', 'Coût au kg'],
            'GELATO.COST_PER_TUB' => ['Costo per vaschetta', 'Cost per tub', 'Kosten pro Behälter', 'Coût par bac'],
            'GELATO.COST_PER_PORTION' => ['Costo per porzione', 'Cost per portion', 'Kosten pro Portion', 'Coût par portion'],
            'GELATO.COST_PER_LITRE_GELATO' => ['Costo per litro di gelato', 'Cost per litre of gelato', 'Kosten pro Liter Glacé', 'Coût par litre de glace'],
            'GELATO.AUTO_BALANCE' => ['Auto-bilanciamento', 'Auto-balance', 'Auto-Bilanz', 'Auto-équilibrage'],
            'GELATO.AUTO_BALANCE.RUN' => ['Calcola le quantità', 'Compute the quantities', 'Mengen berechnen', 'Calculer les quantités'],
            'GELATO.AUTO_BALANCE.EXACT' => ['Tutti gli obiettivi sono raggiunti', 'All targets are met', 'Alle Ziele sind erreicht', 'Tous les objectifs sont atteints'],
            'GELATO.AUTO_BALANCE.CLOSEST' => ['Obiettivi non raggiungibili: soluzione più vicina', 'Targets cannot all be met: closest solution', 'Ziele nicht alle erreichbar: nächstliegende Lösung', 'Objectifs inatteignables : solution la plus proche'],
            'GELATO.TARGET' => ['Obiettivo', 'Target', 'Ziel', 'Objectif'],
            'GELATO.SAVE_RECIPE' => ['Salva ricetta', 'Save recipe', 'Rezept speichern', 'Enregistrer la recette'],
            'GELATO.LOAD_ERROR' => ['Impossibile caricare i dati', 'The data could not be loaded', 'Die Daten konnten nicht geladen werden', 'Les données n\'ont pas pu être chargées'],

            'GELATO.LOTS.TITLE' => ['Lotti di pastorizzazione e mantecazione', 'Pasteurization and churning lots', 'Pasteurisations- und Gefrierchargen', 'Lots de pasteurisation et de turbinage'],
            'GELATO.LOTS.BASE' => ['Base pastorizzata', 'Pasteurized base', 'Pasteurisierte Basis', 'Base pasteurisée'],
            'GELATO.LOTS.FLAVOR' => ['Gusto mantecato', 'Churned flavor', 'Gefrorener Geschmack', 'Parfum turbiné'],
            'GELATO.LOTS.START' => ['Avvia lotto', 'Start lot', 'Charge starten', 'Démarrer le lot'],
            'GELATO.LOTS.COMPLETE' => ['Chiudi lotto', 'Complete lot', 'Charge abschliessen', 'Clôturer le lot'],
            'GELATO.LOTS.PARENT' => ['Base di origine', 'Parent base', 'Ursprungsbasis', 'Base d\'origine'],
            'GELATO.LOTS.GENEALOGY' => ['Tracciabilità', 'Traceability', 'Rückverfolgbarkeit', 'Traçabilité'],
            'GELATO.HACCP.TITLE' => ['Registro HACCP termico', 'HACCP thermal log', 'HACCP-Temperaturprotokoll', 'Registre thermique HACCP'],
            'GELATO.HACCP.ADD' => ['Aggiungi rilevazione', 'Add reading', 'Messwert hinzufügen', 'Ajouter un relevé'],
            'GELATO.HACCP.PASSED' => ['Curva HACCP conforme', 'HACCP curve passed', 'HACCP-Kurve bestanden', 'Courbe HACCP conforme'],
            'GELATO.HACCP.FAILED' => ['Curva HACCP non conforme', 'HACCP curve failed', 'HACCP-Kurve nicht bestanden', 'Courbe HACCP non conforme'],
            'GELATO.HACCP.FINDING.HACCP_PASTEURIZATION_NOT_REACHED' => ['Temperatura di pastorizzazione non raggiunta', 'Pasteurization temperature not reached', 'Pasteurisationstemperatur nicht erreicht', 'Température de pasteurisation non atteinte'],
            'GELATO.HACCP.FINDING.HACCP_COLD_CHAIN_NOT_REACHED' => ['+4 °C non raggiunti', '+4 °C not reached', '+4 °C nicht erreicht', '+4 °C non atteints'],
            'GELATO.HACCP.FINDING.HACCP_COOLING_TOO_SLOW' => ['Raffreddamento troppo lento', 'Cooling too slow', 'Abkühlung zu langsam', 'Refroidissement trop lent'],
            'GELATO.HACCP.FINDING.HACCP_HARDENING_NOT_REACHED' => ['-18 °C non raggiunti', '-18 °C not reached', '-18 °C nicht erreicht', '-18 °C non atteints'],
            'GELATO.HACCP.FINDING.HACCP_NO_READINGS' => ['Nessuna rilevazione', 'No readings', 'Keine Messwerte', 'Aucun relevé'],
            'GELATO.SCALE.TITLE' => ['Postazione di pesatura guidata', 'Guided weighing station', 'Geführte Wägestation', 'Poste de pesée guidée'],
            'GELATO.SCALE.READ' => ['Leggi la bilancia', 'Read the scale', 'Waage auslesen', 'Lire la balance'],
            'GELATO.SCALE.MANUAL' => ['Peso manuale', 'Manual weight', 'Manuelles Gewicht', 'Poids manuel'],
            'GELATO.SCALE.NEXT' => ['Ingrediente successivo', 'Next ingredient', 'Nächste Zutat', 'Ingrédient suivant'],

            'GELATO.GANACHE.TITLE' => ['Studio ganache', 'Ganache studio', 'Ganache-Studio', 'Studio ganache'],
            'GELATO.GANACHE.AW' => ['Attività dell\'acqua (aw)', 'Water activity (aw)', 'Wasseraktivität (aw)', 'Activité de l\'eau (aw)'],
            'GELATO.GANACHE.SHELF_LIFE' => ['Shelf life stimata (giorni)', 'Estimated shelf life (days)', 'Geschätzte Haltbarkeit (Tage)', 'Durée de conservation estimée (jours)'],
            'GELATO.GANACHE.TEMPERING' => ['Curva di temperaggio', 'Tempering curve', 'Temperierkurve', 'Courbe de tempérage'],
            'GELATO.GANACHE.MELT' => ['Fusione', 'Melting', 'Schmelzen', 'Fonte'],
            'GELATO.GANACHE.COOL' => ['Precristallizzazione', 'Pre-crystallization', 'Vorkristallisation', 'Précristallisation'],
            'GELATO.GANACHE.WORK' => ['Lavorazione', 'Working', 'Verarbeitung', 'Travail'],
            'GELATO.GANACHE.DARK' => ['Fondente', 'Dark', 'Dunkel', 'Noir'],
            'GELATO.GANACHE.MILK' => ['Latte', 'Milk', 'Milch', 'Lait'],
            'GELATO.GANACHE.WHITE' => ['Bianco', 'White', 'Weiss', 'Blanc'],
            'GELATO.RISK.stable' => ['Stabile', 'Shelf stable', 'Lagerstabil', 'Stable'],
            'GELATO.RISK.low' => ['Rischio basso', 'Low risk', 'Geringes Risiko', 'Risque faible'],
            'GELATO.RISK.moderate' => ['Rischio moderato', 'Moderate risk', 'Mittleres Risiko', 'Risque modéré'],
            'GELATO.RISK.elevated' => ['Rischio elevato', 'Elevated risk', 'Erhöhtes Risiko', 'Risque élevé'],
            'GELATO.RISK.high' => ['Rischio alto', 'High risk', 'Hohes Risiko', 'Risque haut'],
            'GELATO.RISK.critical' => ['Rischio critico', 'Critical risk', 'Kritisches Risiko', 'Risque critique'],
            'GELATO.ORDERS.TITLE' => ['Preordini', 'Pre-orders', 'Vorbestellungen', 'Précommandes'],
            'GELATO.ORDERS.PRALINE_BOX' => ['Scatola di praline assortite', 'Assorted praline box', 'Assortierte Pralinenschachtel', 'Boîte de pralines assorties'],
            'GELATO.ORDERS.GELATO_CAKE' => ['Torta gelato', 'Gelato cake', 'Glacétorte', 'Gâteau glacé'],
            'GELATO.ORDERS.TAKEAWAY_TUB' => ['Vaschetta da asporto', 'Take-away tub', 'Take-away-Becher', 'Bac à emporter'],
            'GELATO.ORDERS.CUSTOMER' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'GELATO.ORDERS.PICKUP' => ['Ritiro', 'Pickup', 'Abholung', 'Retrait'],
            'GELATO.ORDERS.PRICE' => ['Prezzo', 'Price', 'Preis', 'Prix'],

            'GELATO.SHOWCASE.TITLE' => ['Vetrina digitale e libro allergeni', 'Digital showcase and allergen book', 'Digitale Vitrine und Allergenbuch', 'Vitrine numérique et livre des allergènes'],
            'GELATO.SHOWCASE.SLOT' => ['Pozzetto {n}', 'Well {n}', 'Platz {n}', 'Bac {n}'],
            'GELATO.SHOWCASE.PUBLISH' => ['Pubblica vetrina', 'Publish showcase', 'Vitrine veröffentlichen', 'Publier la vitrine'],
            'GELATO.SHOWCASE.TV' => ['Modalità TV', 'TV mode', 'TV-Modus', 'Mode TV'],
            'GELATO.SHOWCASE.QR' => ['Codice QR per i clienti', 'QR code for customers', 'QR-Code für Kunden', 'Code QR pour les clients'],
            'GELATO.SHOWCASE.INGREDIENTS' => ['Ingredienti (in ordine decrescente di peso)', 'Ingredients (descending weight)', 'Zutaten (absteigend nach Gewicht)', 'Ingrédients (par poids décroissant)'],
            'GELATO.SHOWCASE.ALLERGENS' => ['Allergeni', 'Allergens', 'Allergene', 'Allergènes'],
            'GELATO.SHOWCASE.GLUTEN_FREE' => ['Senza glutine', 'Gluten-free', 'Glutenfrei', 'Sans gluten'],
            'GELATO.SHOWCASE.LACTOSE_FREE' => ['Senza lattosio', 'Lactose-free', 'Laktosefrei', 'Sans lactose'],
            'GELATO.SHOWCASE.VEGAN' => ['Vegano', 'Vegan', 'Vegan', 'Végétalien'],
            'GELATO.SHOWCASE.DEFROSTED' => ['Decongelato', 'Defrosted', 'Aufgetaut', 'Décongelé'],
            'GELATO.SHOWCASE.NOT_PUBLISHED' => ['Nessuna vetrina pubblicata', 'No showcase published', 'Keine Vitrine veröffentlicht', 'Aucune vitrine publiée'],
            'GELATO.SHOWCASE.VERSION' => ['Versione {version}', 'Version {version}', 'Version {version}', 'Version {version}'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = match ($locale) {
            'it' => 0,
            'en' => 1,
            'de' => 2,
            'fr' => 3,
            default => 1,
        };

        return array_map(static fn (array $texts): string => $texts[$index], self::definitions());
    }
}
