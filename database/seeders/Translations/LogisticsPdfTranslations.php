<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * Delivery note (DDT) PDF vocabulary, stored in the translations table (keys PDF.*) and layered over the lang files
 * by DocumentPdfService. Format: key => [it, en, de, fr].
 */
final class LogisticsPdfTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function all(): array
    {
        return [
            'PDF.TITLES.DELIVERY_NOTE' => ['Documento di trasporto (DDT)', 'Delivery note', 'Lieferschein', 'Bon de livraison'],
            'PDF.NUMBER' => ['N.', 'No.', 'Nr.', 'N°'],
            'PDF.DATE' => ['Data', 'Date', 'Datum', 'Date'],
            'PDF.CUSTOMER' => ['Destinatario', 'Customer', 'Kunde', 'Client'],
            'PDF.VAT_NUMBER' => ['P.IVA', 'VAT no.', 'USt-IdNr.', 'N° TVA'],
            'PDF.FISCAL_CODE' => ['Codice fiscale', 'Fiscal code', 'Steuernummer', 'Code fiscal'],
            'PDF.UID_NUMBER' => ['IDI/UID', 'UID', 'UID', 'IDE'],
            'PDF.PAGE' => ['Pagina', 'Page', 'Seite', 'Page'],
            'PDF.NOTES' => ['Note', 'Notes', 'Bemerkungen', 'Notes'],
            'PDF.COLUMNS.DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'PDF.COLUMNS.TYPE' => ['Tipo', 'Type', 'Typ', 'Type'],
            'PDF.COLUMNS.QUANTITY' => ['Q.tà', 'Qty', 'Menge', 'Qté'],
            'PDF.COLUMNS.UNIT_PRICE' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'PDF.COLUMNS.DISCOUNT' => ['Sc. %', 'Disc. %', 'Rab. %', 'Remise %'],
            'PDF.COLUMNS.TAX' => ['IVA %', 'VAT %', 'MWST %', 'TVA %'],
            'PDF.COLUMNS.TOTAL' => ['Totale', 'Total', 'Total', 'Total'],
            'PDF.TOTALS.NET' => ['Imponibile', 'Net total', 'Nettobetrag', 'Total net'],
            'PDF.TOTALS.TAX' => ['IVA', 'VAT', 'MWST', 'TVA'],
            'PDF.TOTALS.GROSS' => ['Totale', 'Total', 'Total', 'Total'],
            'PDF.DELIVERY.SHIP_TO' => ['Destinazione merce', 'Ship to', 'Lieferadresse', 'Adresse de livraison'],
            'PDF.DELIVERY.TRANSPORT' => ['Dati del trasporto', 'Transport details', 'Transportdaten', 'Données du transport'],
            'PDF.DELIVERY.REASON' => ['Causale del trasporto', 'Transport reason', 'Transportgrund', 'Motif du transport'],
            'PDF.DELIVERY.APPEARANCE' => ['Aspetto esteriore dei beni', 'Appearance of goods', 'Äußeres Erscheinungsbild der Ware', 'Aspect extérieur des biens'],
            'PDF.DELIVERY.PACKAGES' => ['Numero colli', 'Number of packages', 'Anzahl Packstücke', 'Nombre de colis'],
            'PDF.DELIVERY.WEIGHT' => ['Peso lordo (kg)', 'Gross weight (kg)', 'Bruttogewicht (kg)', 'Poids brut (kg)'],
            'PDF.DELIVERY.NET_WEIGHT' => ['Peso netto (kg)', 'Net weight (kg)', 'Nettogewicht (kg)', 'Poids net (kg)'],
            'PDF.DELIVERY.VOLUME' => ['Volume (m3)', 'Volume (m3)', 'Volumen (m3)', 'Volume (m3)'],
            'PDF.DELIVERY.CARRIER' => ['Vettore', 'Carrier', 'Frachtführer', 'Transporteur'],
            'PDF.DELIVERY.COURIER' => ['Corriere', 'Courier', 'Kurier', 'Coursier'],
            'PDF.DELIVERY.LICENSE_PLATE' => ['Targa automezzo', 'Vehicle plate', 'Fahrzeugkennzeichen', 'Plaque du véhicule'],
            'PDF.DELIVERY.DEPARTURE' => ['Inizio trasporto', 'Transport start', 'Transportbeginn', 'Début du transport'],
            'PDF.DELIVERY.CARRIAGE_TERMS' => ['Porto', 'Carriage terms', 'Frachtkonditionen', 'Port'],
            'PDF.DELIVERY.TRANSPORT_BY' => ['Trasporto a cura del', 'Transport arranged by', 'Transport durch', 'Transport à la charge de'],
            'PDF.DELIVERY.CARRIER_VAT' => ['P.IVA vettore', 'Carrier VAT no.', 'USt-IdNr. Frachtführer', 'N° TVA transporteur'],
            'PDF.DELIVERY.CARRIER_UID' => ['IDI/UID vettore', 'Carrier UID', 'UID Frachtführer', 'IDE transporteur'],
            'PDF.DELIVERY.INCOTERMS' => ['Incoterms', 'Incoterms', 'Incoterms', 'Incoterms'],
            'PDF.DELIVERY.CARRIAGE.FREE' => ['Franco', 'Carriage paid', 'Frei Haus', 'Franco'],
            'PDF.DELIVERY.CARRIAGE.COLLECT' => ['Assegnato', 'Carriage forward', 'Unfrei', 'Port dû'],
            'PDF.DELIVERY.CARRIAGE.FREE_WITH_CHARGE' => ['Franco con addebito', 'Carriage paid, charged back', 'Frei Haus mit Berechnung', 'Franco avec refacturation'],
            'PDF.DELIVERY.BY.SENDER' => ['Mittente', 'Sender', 'Absender', 'Expéditeur'],
            'PDF.DELIVERY.BY.RECIPIENT' => ['Destinatario', 'Recipient', 'Empfänger', 'Destinataire'],
            'PDF.DELIVERY.BY.CARRIER' => ['Vettore', 'Carrier', 'Frachtführer', 'Transporteur'],
            'PDF.DELIVERY.SIGN_SENDER' => ['Firma mittente', 'Sender signature', 'Unterschrift Absender', 'Signature de l\'expéditeur'],
            'PDF.DELIVERY.SIGN_DRIVER' => ['Firma conducente', 'Driver signature', 'Unterschrift Fahrer', 'Signature du conducteur'],
            'PDF.DELIVERY.SIGN_CARRIER' => ['Firma vettore', 'Carrier signature', 'Unterschrift Frachtführer', 'Signature du transporteur'],
            'PDF.DELIVERY.RECEIPT' => ['Firma destinatario per ricevuta (merce ricevuta in buono stato)', 'Recipient signature (goods received in good condition)', 'Unterschrift Empfänger (Ware in gutem Zustand erhalten)', 'Signature du destinataire (marchandise reçue en bon état)'],
        ];
    }
}
