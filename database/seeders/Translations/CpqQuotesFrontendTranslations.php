<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the back-office quote builder and the revision diff viewer (Mod 036). Each entry is [it, en, de, fr]; the
 * Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH. Placeholders are written {name}.
 */
final class CpqQuotesFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'CPQ_QUOTES.NAV' => ['Preventivi interattivi', 'Interactive quotes', 'Interaktive Angebote', 'Devis interactifs'],
            'CPQ_QUOTES.TITLE' => ['Preventivi e proposte commerciali', 'Quotes and commercial proposals', 'Angebote und Geschäftsvorschläge', 'Devis et propositions commerciales'],
            'CPQ_QUOTES.EDITOR_TITLE' => ['Editor del preventivo', 'Quote editor', 'Angebotseditor', 'Éditeur de devis'],
            'CPQ_QUOTES.HISTORY_TITLE' => ['Storico revisioni e confronto', 'Revision history and comparison', 'Revisionsverlauf und Vergleich', 'Historique des révisions et comparaison'],
            'CPQ_QUOTES.HISTORY' => ['Revisioni', 'Revisions', 'Revisionen', 'Révisions'],
            'CPQ_QUOTES.NEW' => ['Nuovo preventivo', 'New quote', 'Neues Angebot', 'Nouveau devis'],
            'CPQ_QUOTES.NUMBER' => ['Numero', 'Number', 'Nummer', 'Numéro'],
            'CPQ_QUOTES.STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'CPQ_QUOTES.FILTER_STATUS' => ['Filtra per stato', 'Filter by status', 'Nach Status filtern', 'Filtrer par statut'],
            'CPQ_QUOTES.VALID_UNTIL' => ['Valido fino al', 'Valid until', 'Gültig bis', 'Valable jusqu’au'],
            'CPQ_QUOTES.ERROR' => ['Operazione non riuscita, riprova', 'The operation failed, please try again', 'Der Vorgang ist fehlgeschlagen, bitte erneut versuchen', 'L’opération a échoué, veuillez réessayer'],

            'CPQ_QUOTES.STATUS_DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'CPQ_QUOTES.STATUS_PENDING_APPROVAL' => ['In approvazione', 'Pending approval', 'Freigabe ausstehend', 'En attente d’approbation'],
            'CPQ_QUOTES.STATUS_SENT' => ['Inviato', 'Sent', 'Gesendet', 'Envoyé'],
            'CPQ_QUOTES.STATUS_VIEWED' => ['Visualizzato', 'Viewed', 'Angesehen', 'Consulté'],
            'CPQ_QUOTES.STATUS_NEGOTIATING' => ['In trattativa', 'Negotiating', 'In Verhandlung', 'En négociation'],
            'CPQ_QUOTES.STATUS_ACCEPTED' => ['Accettato', 'Accepted', 'Angenommen', 'Accepté'],
            'CPQ_QUOTES.STATUS_REJECTED' => ['Rifiutato', 'Rejected', 'Abgelehnt', 'Refusé'],
            'CPQ_QUOTES.STATUS_EXPIRED' => ['Scaduto', 'Expired', 'Abgelaufen', 'Expiré'],
            'CPQ_QUOTES.STATUS_SUPERSEDED' => ['Sostituito', 'Superseded', 'Ersetzt', 'Remplacé'],
            'CPQ_QUOTES.STATUS_CONVERTED' => ['Convertito', 'Converted', 'Umgewandelt', 'Converti'],

            'CPQ_QUOTES.CUSTOMER' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'CPQ_QUOTES.CUSTOMER_EMAIL' => ['Email del cliente', 'Customer email', 'E-Mail des Kunden', 'E-mail du client'],
            'CPQ_QUOTES.CURRENCY' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'CPQ_QUOTES.JURISDICTION' => ['Giurisdizione fiscale', 'Tax jurisdiction', 'Steuerhoheit', 'Juridiction fiscale'],
            'CPQ_QUOTES.HEADER_DISCOUNT' => ['Sconto di testata %', 'Header discount %', 'Kopfrabatt %', 'Remise d’en-tête %'],
            'CPQ_QUOTES.MIN_MARGIN' => ['Margine minimo %', 'Minimum margin %', 'Mindestmarge %', 'Marge minimale %'],
            'CPQ_QUOTES.COVER_LETTER' => ['Lettera di presentazione', 'Cover letter', 'Begleitschreiben', 'Lettre de présentation'],

            'CPQ_QUOTES.ADD_SECTION' => ['Aggiungi sezione', 'Add section', 'Abschnitt hinzufügen', 'Ajouter une section'],
            'CPQ_QUOTES.ADD_PRODUCT' => ['+ Prodotto', '+ Product', '+ Produkt', '+ Produit'],
            'CPQ_QUOTES.ADD_SERVICE' => ['+ Servizio a corpo', '+ Fixed-fee service', '+ Pauschaldienstleistung', '+ Service au forfait'],
            'CPQ_QUOTES.ADD_HOURLY' => ['+ Servizio a tempo', '+ Time and material', '+ Dienstleistung nach Aufwand', '+ Service en régie'],
            'CPQ_QUOTES.ADD_TEXT' => ['+ Nota', '+ Text note', '+ Textnotiz', '+ Note'],
            'CPQ_QUOTES.ADD_SUBTOTAL' => ['+ Subtotale', '+ Subtotal', '+ Zwischensumme', '+ Sous-total'],
            'CPQ_QUOTES.REMOVE' => ['Rimuovi', 'Remove', 'Entfernen', 'Supprimer'],
            'CPQ_QUOTES.DRAG_SECTION' => ['Trascina la sezione', 'Drag the section', 'Abschnitt ziehen', 'Glisser la section'],
            'CPQ_QUOTES.DRAG_LINE' => ['Trascina la riga', 'Drag the line', 'Position ziehen', 'Glisser la ligne'],
            'CPQ_QUOTES.SECTION_TITLE' => ['Titolo della sezione', 'Section title', 'Abschnittstitel', 'Titre de la section'],
            'CPQ_QUOTES.SECTION_DISCOUNT' => ['Sconto sezione %', 'Section discount %', 'Abschnittsrabatt %', 'Remise de section %'],
            'CPQ_QUOTES.DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'CPQ_QUOTES.QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'CPQ_QUOTES.UNIT_PRICE' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'CPQ_QUOTES.DISCOUNT' => ['Sconto %', 'Discount %', 'Rabatt %', 'Remise %'],
            'CPQ_QUOTES.TAX_RATE' => ['Aliquota %', 'VAT rate %', 'Steuersatz %', 'Taux de TVA %'],
            'CPQ_QUOTES.MODE' => ['Modalità della riga', 'Line mode', 'Positionsmodus', 'Mode de la ligne'],
            'CPQ_QUOTES.MODE_MANDATORY' => ['Inclusa', 'Mandatory', 'Verbindlich', 'Obligatoire'],
            'CPQ_QUOTES.MODE_OPTIONAL' => ['Opzionale', 'Optional', 'Optional', 'Optionnelle'],
            'CPQ_QUOTES.MODE_ALTERNATIVE' => ['Alternativa', 'Alternative', 'Alternative', 'Alternative'],
            'CPQ_QUOTES.ALTERNATIVE_GROUP' => ['Gruppo di alternative', 'Alternative group', 'Alternativgruppe', 'Groupe d’alternatives'],
            'CPQ_QUOTES.DEFAULT_ON' => ['Proposta di default', 'Offered by default', 'Standardmäßig angeboten', 'Proposée par défaut'],

            'CPQ_QUOTES.SAVE' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'CPQ_QUOTES.ISSUE' => ['Emetti e invia', 'Issue and send', 'Ausstellen und senden', 'Émettre et envoyer'],
            'CPQ_QUOTES.APPROVE_AND_ISSUE' => ['Approva ed emetti', 'Approve and issue', 'Freigeben und ausstellen', 'Approuver et émettre'],
            'CPQ_QUOTES.USE_PIN' => ['Proteggi con PIN', 'Protect with a PIN', 'Mit PIN schützen', 'Protéger par PIN'],
            'CPQ_QUOTES.ISSUED' => ['Preventivo emesso: link per il cliente', 'Quote issued: link for the customer', 'Angebot ausgestellt: Link für den Kunden', 'Devis émis : lien pour le client'],
            'CPQ_QUOTES.PENDING_APPROVAL' => ['Margine sotto la soglia: in attesa di approvazione', 'Margin below the guardrail: waiting for approval', 'Marge unter der Schwelle: wartet auf Freigabe', 'Marge sous le seuil : en attente d’approbation'],
            'CPQ_QUOTES.GUARDRAIL' => ['Il margine è sotto la soglia minima', 'The margin is below the minimum threshold', 'Die Marge liegt unter der Mindestschwelle', 'La marge est inférieure au seuil minimal'],
            'CPQ_QUOTES.NEW_REVISION' => ['Crea nuova revisione', 'Create new revision', 'Neue Revision erstellen', 'Créer une nouvelle révision'],
            'CPQ_QUOTES.CONVERT_ORDER' => ['Converti in ordine di vendita', 'Convert to sales order', 'In Verkaufsauftrag umwandeln', 'Convertir en commande client'],
            'CPQ_QUOTES.CONVERT_INVOICE' => ['Converti in fattura', 'Convert to invoice', 'In Rechnung umwandeln', 'Convertir en facture'],
            'CPQ_QUOTES.DEPOSIT_PERCENT' => ['Acconto % (vuoto = intero)', 'Down payment % (empty = whole)', 'Anzahlung % (leer = gesamt)', 'Acompte % (vide = total)'],

            'CPQ_QUOTES.MARGIN_DRAWER' => ['Margini e redditività', 'Margin and profitability', 'Marge und Rentabilität', 'Marge et rentabilité'],
            'CPQ_QUOTES.UNIT_COST' => ['Costo unitario', 'Unit cost', 'Stückkosten', 'Coût unitaire'],
            'CPQ_QUOTES.TOTAL_COST' => ['Costo totale', 'Total cost', 'Gesamtkosten', 'Coût total'],
            'CPQ_QUOTES.MARGIN' => ['Margine', 'Margin', 'Marge', 'Marge'],

            'CPQ_QUOTES.TOTAL_NET' => ['Totale netto', 'Net total', 'Nettosumme', 'Total net'],
            'CPQ_QUOTES.TOTAL_TAX' => ['IVA / imposta', 'VAT / tax', 'MwSt. / Steuer', 'TVA / taxe'],
            'CPQ_QUOTES.TOTAL_GROSS' => ['Totale lordo', 'Gross total', 'Bruttosumme', 'Total brut'],
            'CPQ_QUOTES.TOTAL_PAYABLE' => ['Totale da pagare', 'Total payable', 'Zu zahlender Betrag', 'Total à payer'],
            'CPQ_QUOTES.TAX_AT' => ['IVA al {rate}%', 'VAT at {rate}%', 'MwSt. zu {rate}%', 'TVA à {rate}%'],
            'CPQ_QUOTES.ROUNDING' => ['Arrotondamento', 'Rounding', 'Rundung', 'Arrondi'],

            'CPQ_QUOTES.COMPARE_FROM' => ['Da', 'From', 'Von', 'De'],
            'CPQ_QUOTES.COMPARE_TO' => ['A', 'To', 'Nach', 'À'],
            'CPQ_QUOTES.COMPARE' => ['Confronta', 'Compare', 'Vergleichen', 'Comparer'],
            'CPQ_QUOTES.VIEW_UNIFIED' => ['Unificata', 'Unified', 'Einheitlich', 'Unifiée'],
            'CPQ_QUOTES.VIEW_SIDE' => ['Affiancata', 'Side by side', 'Nebeneinander', 'Côte à côte'],
            'CPQ_QUOTES.NO_CHANGES' => ['Le due revisioni sono identiche', 'The two revisions are identical', 'Die beiden Revisionen sind identisch', 'Les deux révisions sont identiques'],
            'CPQ_QUOTES.DELTA' => ['Variazione', 'Variance', 'Abweichung', 'Écart'],
            'CPQ_QUOTES.DIFF_SECTION' => ['Sezione', 'Section', 'Abschnitt', 'Section'],
            'CPQ_QUOTES.DIFF_LINE' => ['Riga', 'Line', 'Position', 'Ligne'],
            'CPQ_QUOTES.FIELD_TITLE' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'CPQ_QUOTES.FIELD_DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'CPQ_QUOTES.FIELD_QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'CPQ_QUOTES.FIELD_UNIT_PRICE' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'CPQ_QUOTES.FIELD_DISCOUNT_PERCENT' => ['Sconto %', 'Discount %', 'Rabatt %', 'Remise %'],
            'CPQ_QUOTES.FIELD_DISCOUNT_FIXED' => ['Sconto fisso', 'Fixed discount', 'Fixrabatt', 'Remise fixe'],
            'CPQ_QUOTES.FIELD_TAX_RATE' => ['Aliquota', 'VAT rate', 'Steuersatz', 'Taux de TVA'],
            'CPQ_QUOTES.FIELD_SELECTION_MODE' => ['Modalità', 'Mode', 'Modus', 'Mode'],
            'CPQ_QUOTES.FIELD_ALTERNATIVE_GROUP' => ['Gruppo di alternative', 'Alternative group', 'Alternativgruppe', 'Groupe d’alternatives'],
            'CPQ_QUOTES.FIELD_IS_DEFAULT_SELECTED' => ['Proposta di default', 'Offered by default', 'Standardmäßig angeboten', 'Proposée par défaut'],
            'CPQ_QUOTES.FIELD_SECTION_ORIGIN_ID' => ['Sezione', 'Section', 'Abschnitt', 'Section'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = match ($locale) {
            'it' => 0,
            'de' => 2,
            'fr' => 3,
            default => 1,
        };

        return array_map(static fn (array $values): string => $values[$index], self::definitions());
    }
}
