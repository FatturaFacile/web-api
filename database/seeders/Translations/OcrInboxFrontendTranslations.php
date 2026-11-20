<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the AI Inbox and document review workbench (Mod 092): queue, trust banner, side-by-side viewer, extraction
 * form, three-way match panel, line grid, totals reconciliation and actions. Each entry is [it, en, de, fr]; the Italian
 * text serves it-CH and it-IT, the German text de-CH and the French text fr-CH. Placeholders are written {name}.
 */
final class OcrInboxFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'OCR_INBOX.NAV' => ['Posta AI', 'AI Inbox', 'KI-Eingang', 'Boîte IA'],
            'OCR_INBOX.TITLE' => ['Posta AI e revisione documenti', 'AI Inbox and document review', 'KI-Eingang und Dokumentenprüfung', 'Boîte IA et contrôle des documents'],
            'OCR_INBOX.SUBTITLE' => [
                'Fatture fornitori, scontrini, DDT e offerte letti automaticamente: controlla, correggi e registra.',
                'Vendor invoices, receipts, delivery notes and quotes read automatically: check, correct and post.',
                'Lieferantenrechnungen, Belege, Lieferscheine und Offerten automatisch gelesen: prüfen, korrigieren und buchen.',
                'Factures fournisseurs, justificatifs, bons de livraison et offres lus automatiquement : contrôlez, corrigez et comptabilisez.',
            ],
            'OCR_INBOX.QUEUE' => ['Coda documenti', 'Document queue', 'Dokumentenwarteschlange', 'File des documents'],
            'OCR_INBOX.EMPTY_QUEUE' => ['Nessun documento in coda', 'No documents in the queue', 'Keine Dokumente in der Warteschlange', 'Aucun document dans la file'],
            'OCR_INBOX.SELECT_DOCUMENT' => ['Seleziona un documento dalla coda per iniziare la revisione', 'Select a document from the queue to start the review', 'Wählen Sie ein Dokument aus der Warteschlange, um die Prüfung zu beginnen', 'Sélectionnez un document dans la file pour commencer le contrôle'],
            'OCR_INBOX.LOAD_ERROR' => ['Impossibile caricare la posta AI', 'The AI Inbox could not be loaded', 'Der KI-Eingang konnte nicht geladen werden', 'La boîte IA n’a pas pu être chargée'],
            'OCR_INBOX.WORKBENCH_ERROR' => ['Impossibile caricare il documento', 'The document could not be loaded', 'Das Dokument konnte nicht geladen werden', 'Le document n’a pas pu être chargé'],
            'OCR_INBOX.REFRESH' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'OCR_INBOX.LIVE_UPDATE' => ['Un nuovo documento è pronto per la revisione', 'A new document is ready for review', 'Ein neues Dokument ist zur Prüfung bereit', 'Un nouveau document est prêt à être contrôlé'],

            'OCR_INBOX.STATUS_QUEUED' => ['In coda', 'Queued', 'In Warteschlange', 'En file'],
            'OCR_INBOX.STATUS_PROCESSING' => ['In elaborazione', 'Processing', 'In Bearbeitung', 'En cours de traitement'],
            'OCR_INBOX.STATUS_READY_FOR_REVIEW' => ['Da rivedere', 'Ready for review', 'Zur Prüfung bereit', 'À contrôler'],
            'OCR_INBOX.STATUS_CONFIRMED' => ['Confermato', 'Confirmed', 'Bestätigt', 'Confirmé'],
            'OCR_INBOX.STATUS_FAILED' => ['Non riuscito', 'Failed', 'Fehlgeschlagen', 'Échec'],
            'OCR_INBOX.STATUS_REJECTED' => ['Rifiutato', 'Rejected', 'Abgelehnt', 'Rejeté'],

            'OCR_INBOX.CATEGORY_VENDOR_INVOICE' => ['Fattura fornitore', 'Vendor invoice', 'Lieferantenrechnung', 'Facture fournisseur'],
            'OCR_INBOX.CATEGORY_EXPENSE_RECEIPT' => ['Scontrino di spesa', 'Expense receipt', 'Spesenbeleg', 'Justificatif de dépense'],
            'OCR_INBOX.CATEGORY_TRANSPORT_DDT' => ['DDT / Lieferschein', 'Delivery note (DDT)', 'Lieferschein (DDT)', 'Bon de livraison (DDT)'],
            'OCR_INBOX.CATEGORY_VENDOR_QUOTE' => ['Offerta fornitore', 'Vendor quote', 'Lieferantenofferte', 'Offre fournisseur'],

            'OCR_INBOX.CHANNEL_DMS_UPLOAD' => ['Archivio documentale', 'Document archive', 'Dokumentenarchiv', 'Archive de documents'],
            'OCR_INBOX.CHANNEL_TENANT_EMAIL_INBOX' => ['E-mail dedicata', 'Dedicated e-mail', 'Eigene E-Mail-Adresse', 'E-mail dédiée'],
            'OCR_INBOX.CHANNEL_MOBILE_CAMERA' => ['Fotocamera app', 'App camera', 'App-Kamera', 'Caméra de l’appli'],
            'OCR_INBOX.CHANNEL_DESKTOP_SCAN' => ['Scansione desktop', 'Desktop scan', 'Desktop-Scan', 'Numérisation bureau'],
            'OCR_INBOX.CHANNEL_API_WEBHOOK' => ['API / webhook', 'API / webhook', 'API / Webhook', 'API / webhook'],

            'OCR_INBOX.BANNER_QR' => ['100% deterministico: QR-fattura svizzera', '100% deterministic Swiss QR-Bill', '100% deterministisch: Schweizer QR-Rechnung', '100 % déterministe : QR-facture suisse'],
            'OCR_INBOX.BANNER_XML' => ['100% deterministico: FatturaPA / ZUGFeRD XML', '100% deterministic FatturaPA / ZUGFeRD XML', '100% deterministisch: FatturaPA / ZUGFeRD-XML', '100 % déterministe : FatturaPA / ZUGFeRD XML'],
            'OCR_INBOX.BANNER_OCR' => ['OCR AI con visione ({provider})', 'AI Vision OCR ({provider})', 'KI-Bilderkennung ({provider})', 'OCR IA à vision ({provider})'],
            'OCR_INBOX.BANNER_CONFIDENCE' => ['Affidabilità complessiva {score}%', 'Overall confidence {score}%', 'Gesamtzuverlässigkeit {score}%', 'Fiabilité globale {score} %'],
            'OCR_INBOX.BANNER_QR_WARNING' => ['Righe e ripartizione IVA non letti: inseriscili a mano.', 'Lines and VAT split could not be read: enter them manually.', 'Positionen und MWST-Aufteilung konnten nicht gelesen werden: bitte manuell erfassen.', 'Lignes et ventilation de TVA non lues : saisissez-les manuellement.'],
            'OCR_INBOX.PROVIDER_OLLAMA_LOCAL' => ['Ollama (locale)', 'Ollama (local)', 'Ollama (lokal)', 'Ollama (local)'],
            'OCR_INBOX.PROVIDER_LAYOUTLMV3_LOCAL' => ['LayoutLMv3 (locale)', 'LayoutLMv3 (local)', 'LayoutLMv3 (lokal)', 'LayoutLMv3 (local)'],
            'OCR_INBOX.PROVIDER_TESSERACT_LOCAL' => ['Tesseract (locale)', 'Tesseract (local)', 'Tesseract (lokal)', 'Tesseract (local)'],
            'OCR_INBOX.PROVIDER_GEMINI_CLOUD' => ['Google Gemini', 'Google Gemini', 'Google Gemini', 'Google Gemini'],
            'OCR_INBOX.PROVIDER_OPENAI_CLOUD' => ['OpenAI', 'OpenAI', 'OpenAI', 'OpenAI'],
            'OCR_INBOX.PROVIDER_AZURE_DOCUMENT_INTELLIGENCE' => ['Azure Document Intelligence', 'Azure Document Intelligence', 'Azure Document Intelligence', 'Azure Document Intelligence'],

            'OCR_INBOX.CONFIDENCE_HIGH' => ['Verificato', 'Verified', 'Verifiziert', 'Vérifié'],
            'OCR_INBOX.CONFIDENCE_MEDIUM' => ['Revisione consigliata', 'Review recommended', 'Prüfung empfohlen', 'Contrôle recommandé'],
            'OCR_INBOX.CONFIDENCE_LOW' => ['Verifica manuale richiesta', 'Manual verification required', 'Manuelle Prüfung erforderlich', 'Vérification manuelle requise'],
            'OCR_INBOX.CONFIDENCE_VALUE' => ['{score}% di affidabilità', '{score}% confidence', '{score}% Zuverlässigkeit', '{score} % de fiabilité'],

            'OCR_INBOX.VIEWER' => ['Documento originale', 'Source document', 'Originaldokument', 'Document d’origine'],
            'OCR_INBOX.ZOOM_IN' => ['Ingrandisci', 'Zoom in', 'Vergrössern', 'Agrandir'],
            'OCR_INBOX.ZOOM_OUT' => ['Riduci', 'Zoom out', 'Verkleinern', 'Réduire'],
            'OCR_INBOX.ZOOM_LEVEL' => ['Zoom {zoom}%', 'Zoom {zoom}%', 'Zoom {zoom}%', 'Zoom {zoom} %'],
            'OCR_INBOX.ROTATE' => ['Ruota', 'Rotate', 'Drehen', 'Pivoter'],
            'OCR_INBOX.PREVIOUS_PAGE' => ['Pagina precedente', 'Previous page', 'Vorherige Seite', 'Page précédente'],
            'OCR_INBOX.NEXT_PAGE' => ['Pagina successiva', 'Next page', 'Nächste Seite', 'Page suivante'],
            'OCR_INBOX.PAGE_OF' => ['Pagina {page} di {pages}', 'Page {page} of {pages}', 'Seite {page} von {pages}', 'Page {page} sur {pages}'],
            'OCR_INBOX.VIEWER_ERROR' => ['Impossibile mostrare il documento', 'The document could not be displayed', 'Das Dokument konnte nicht angezeigt werden', 'Le document n’a pas pu être affiché'],
            'OCR_INBOX.REGION_SELECT' => ['Seleziona un\'area per assegnarla al campo attivo', 'Draw a region to assign it to the active field', 'Bereich aufziehen, um ihn dem aktiven Feld zuzuweisen', 'Tracez une zone pour l’attribuer au champ actif'],
            'OCR_INBOX.REGION_LEGEND' => ['Legenda delle aree', 'Region legend', 'Legende der Bereiche', 'Légende des zones'],

            'OCR_INBOX.SECTION_HEADER' => ['Dati del documento', 'Document details', 'Dokumentdaten', 'Données du document'],
            'OCR_INBOX.VENDOR' => ['Fornitore', 'Vendor', 'Lieferant', 'Fournisseur'],
            'OCR_INBOX.VENDOR_SEARCH' => ['Cerca fornitore', 'Search vendor', 'Lieferant suchen', 'Rechercher un fournisseur'],
            'OCR_INBOX.VENDOR_NOT_FOUND' => ['Fornitore non riconosciuto', 'Vendor not recognised', 'Lieferant nicht erkannt', 'Fournisseur non reconnu'],
            'OCR_INBOX.VENDOR_CREATE' => ['Crea fornitore dai dati estratti', 'Create vendor from extracted data', 'Lieferant aus extrahierten Daten anlegen', 'Créer le fournisseur à partir des données extraites'],
            'OCR_INBOX.VENDOR_NAME' => ['Ragione sociale', 'Company name', 'Firmenname', 'Raison sociale'],
            'OCR_INBOX.UID' => ['IDI / UID (CHE)', 'UID (CHE)', 'UID (CHE)', 'IDE / UID (CHE)'],
            'OCR_INBOX.VAT_NUMBER' => ['Partita IVA', 'VAT number', 'MWST-Nummer', 'Numéro de TVA'],
            'OCR_INBOX.FISCAL_CODE' => ['Codice fiscale', 'Tax code', 'Steuernummer', 'Code fiscal'],
            'OCR_INBOX.IBAN' => ['IBAN / QR-IBAN', 'IBAN / QR-IBAN', 'IBAN / QR-IBAN', 'IBAN / QR-IBAN'],
            'OCR_INBOX.DOCUMENT_NUMBER' => ['Numero documento', 'Document number', 'Dokumentnummer', 'Numéro du document'],
            'OCR_INBOX.ISSUE_DATE' => ['Data documento', 'Issue date', 'Ausstellungsdatum', 'Date du document'],
            'OCR_INBOX.DUE_DATE' => ['Scadenza', 'Due date', 'Fälligkeitsdatum', 'Échéance'],
            'OCR_INBOX.CURRENCY' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'OCR_INBOX.QR_REFERENCE' => ['Riferimento QRR / SCOR', 'QRR / SCOR reference', 'QRR- / SCOR-Referenz', 'Référence QRR / SCOR'],
            'OCR_INBOX.CATEGORY' => ['Tipo di documento', 'Document type', 'Dokumenttyp', 'Type de document'],
            'OCR_INBOX.EXPENSE_CATEGORY' => ['Categoria di spesa', 'Expense category', 'Spesenkategorie', 'Catégorie de dépense'],
            'OCR_INBOX.RFQ' => ['Richiesta di offerta', 'Request for quotation', 'Offertanfrage', 'Demande d’offre'],

            'OCR_INBOX.MATCH_TITLE' => ['Controllo a tre vie', 'Three-way matching', 'Dreifach-Abgleich', 'Rapprochement à trois voies'],
            'OCR_INBOX.MATCH_NONE' => ['Nessun ordine d\'acquisto aperto corrisponde al documento', 'No open purchase order matches this document', 'Keine offene Bestellung passt zu diesem Dokument', 'Aucune commande d’achat ouverte ne correspond à ce document'],
            'OCR_INBOX.MATCH_PURCHASE_ORDER' => ['Ordine d\'acquisto {number}', 'Purchase order {number}', 'Bestellung {number}', 'Commande d’achat {number}'],
            'OCR_INBOX.MATCH_GOODS_RECEIPTS' => ['Ricevimenti merce', 'Goods receipts', 'Wareneingänge', 'Réceptions de marchandises'],
            'OCR_INBOX.MATCH_MATCHED' => ['Conforme', 'Matched', 'Übereinstimmend', 'Conforme'],
            'OCR_INBOX.MATCH_VARIANCE' => ['Scostamenti', 'Variances', 'Abweichungen', 'Écarts'],
            'OCR_INBOX.MATCH_UNMATCHED' => ['Non abbinato', 'Unmatched', 'Nicht zugeordnet', 'Non rapproché'],
            'OCR_INBOX.MATCH_PRICE_VARIANCE' => ['Scostamento di prezzo {value}%', 'Price variance {value}%', 'Preisabweichung {value}%', 'Écart de prix {value} %'],
            'OCR_INBOX.MATCH_QUANTITY_VARIANCE' => ['Scostamento di quantità {value}', 'Quantity variance {value}', 'Mengenabweichung {value}', 'Écart de quantité {value}'],
            'OCR_INBOX.MATCH_RECEIVED_VARIANCE' => ['Fatturato oltre il ricevuto: {value}', 'Billed above received: {value}', 'Über den Wareneingang hinaus verrechnet: {value}', 'Facturé au-delà du reçu : {value}'],
            'OCR_INBOX.MATCH_LINK_LINE' => ['Collega la riga', 'Link line', 'Position verknüpfen', 'Lier la ligne'],
            'OCR_INBOX.MATCH_LINKED' => ['Collegata', 'Linked', 'Verknüpft', 'Liée'],
            'OCR_INBOX.MATCH_OVERRIDE_REASON' => ['Motivo della deroga alla tolleranza', 'Reason for overriding the tolerance', 'Begründung für die Toleranzabweichung', 'Motif de la dérogation à la tolérance'],

            'OCR_INBOX.LINES' => ['Righe del documento', 'Document lines', 'Dokumentpositionen', 'Lignes du document'],
            'OCR_INBOX.LINE_DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'OCR_INBOX.LINE_QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'OCR_INBOX.LINE_UNIT_PRICE' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'OCR_INBOX.LINE_NET_AMOUNT' => ['Importo netto', 'Net amount', 'Nettobetrag', 'Montant net'],
            'OCR_INBOX.LINE_VAT_CODE' => ['Codice IVA', 'VAT code', 'MWST-Code', 'Code TVA'],
            'OCR_INBOX.LINE_GL_ACCOUNT' => ['Conto di costo', 'Expense account', 'Aufwandkonto', 'Compte de charge'],
            'OCR_INBOX.LINE_COST_CENTER' => ['Centro di costo', 'Cost center', 'Kostenstelle', 'Centre de coûts'],
            'OCR_INBOX.LINE_AI_CONFIDENCE' => ['Affidabilità AI', 'AI confidence', 'KI-Zuverlässigkeit', 'Fiabilité IA'],
            'OCR_INBOX.LINE_CODING_SOURCE_EXACT' => ['Appreso da questo fornitore', 'Learned from this vendor', 'Von diesem Lieferanten gelernt', 'Appris de ce fournisseur'],
            'OCR_INBOX.LINE_CODING_SOURCE_FUZZY' => ['Riga simile già registrata', 'Similar line posted before', 'Ähnliche Position früher gebucht', 'Ligne similaire déjà comptabilisée'],
            'OCR_INBOX.LINE_CODING_SOURCE_VENDOR_DEFAULT' => ['Impostazione abituale del fornitore', 'Usual coding of the vendor', 'Übliche Kontierung des Lieferanten', 'Imputation habituelle du fournisseur'],
            'OCR_INBOX.LINE_CODING_SOURCE_NONE' => ['Nessun suggerimento', 'No suggestion', 'Kein Vorschlag', 'Aucune suggestion'],
            'OCR_INBOX.ADD_LINE' => ['Aggiungi riga', 'Add line', 'Position hinzufügen', 'Ajouter une ligne'],
            'OCR_INBOX.REMOVE_LINE' => ['Rimuovi riga', 'Remove line', 'Position entfernen', 'Supprimer la ligne'],

            'OCR_INBOX.TOTAL_NET' => ['Totale imponibile', 'Total net', 'Total netto', 'Total HT'],
            'OCR_INBOX.TOTAL_VAT' => ['Totale IVA', 'Total VAT', 'Total MWST', 'Total TVA'],
            'OCR_INBOX.TOTAL_GROSS' => ['Totale documento', 'Gross total', 'Total brutto', 'Total TTC'],
            'OCR_INBOX.TOTALS_RECONCILED' => ['I totali quadrano', 'The totals reconcile', 'Die Summen stimmen überein', 'Les totaux concordent'],
            'OCR_INBOX.TOTALS_DIFFERENCE' => ['Differenza di {difference} {currency} (tolleranza {tolerance})', 'Difference of {difference} {currency} (tolerance {tolerance})', 'Differenz von {difference} {currency} (Toleranz {tolerance})', 'Écart de {difference} {currency} (tolérance {tolerance})'],
            'OCR_INBOX.VAT_BREAKDOWN' => ['Ripartizione IVA', 'VAT breakdown', 'MWST-Aufteilung', 'Ventilation de la TVA'],
            'OCR_INBOX.VAT_RATE' => ['Aliquota', 'Rate', 'Satz', 'Taux'],

            'OCR_INBOX.CONFIRM' => ['Conferma e registra', 'Confirm & post document', 'Bestätigen und buchen', 'Confirmer et comptabiliser'],
            'OCR_INBOX.SAVE_DRAFT' => ['Salva bozza', 'Save draft', 'Entwurf speichern', 'Enregistrer le brouillon'],
            'OCR_INBOX.RERUN' => ['Rileggi il documento', 'Re-run extraction', 'Extraktion wiederholen', 'Relancer l’extraction'],
            'OCR_INBOX.REJECT' => ['Rifiuta / non valido', 'Reject / mark invalid', 'Ablehnen / ungültig', 'Rejeter / invalide'],
            'OCR_INBOX.REJECT_REASON' => ['Motivo del rifiuto', 'Reason for rejecting', 'Grund der Ablehnung', 'Motif du rejet'],
            'OCR_INBOX.REJECT_CONFIRM' => ['Rifiutare questo documento? Non verrà creato nulla.', 'Reject this document? Nothing will be created.', 'Dieses Dokument ablehnen? Es wird nichts erstellt.', 'Rejeter ce document ? Rien ne sera créé.'],
            'OCR_INBOX.CONFIRMED_OK' => ['Documento registrato', 'Document posted', 'Dokument gebucht', 'Document comptabilisé'],
            'OCR_INBOX.CONFIRM_ERROR' => ['Impossibile registrare il documento', 'The document could not be posted', 'Das Dokument konnte nicht gebucht werden', 'Le document n’a pas pu être comptabilisé'],
            'OCR_INBOX.DRAFT_SAVED' => ['Bozza salvata', 'Draft saved', 'Entwurf gespeichert', 'Brouillon enregistré'],
            'OCR_INBOX.DRAFT_ERROR' => ['Impossibile salvare la bozza', 'The draft could not be saved', 'Der Entwurf konnte nicht gespeichert werden', 'Le brouillon n’a pas pu être enregistré'],
            'OCR_INBOX.REJECTED_OK' => ['Documento rifiutato', 'Document rejected', 'Dokument abgelehnt', 'Document rejeté'],
            'OCR_INBOX.REJECT_ERROR' => ['Impossibile rifiutare il documento', 'The document could not be rejected', 'Das Dokument konnte nicht abgelehnt werden', 'Le document n’a pas pu être rejeté'],
            'OCR_INBOX.RERUN_OK' => ['Lettura rimessa in coda', 'Extraction queued again', 'Extraktion erneut eingereiht', 'Extraction remise en file'],
            'OCR_INBOX.RERUN_ERROR' => ['Impossibile rimettere in coda il documento', 'The document could not be queued again', 'Das Dokument konnte nicht erneut eingereiht werden', 'Le document n’a pas pu être remis en file'],
            'OCR_INBOX.FIELD_REQUIRED' => ['Campo obbligatorio', 'Required field', 'Pflichtfeld', 'Champ obligatoire'],
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
