<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of Mod 045 (Request for Quotation, Supplier Bidding Portal, Side-by-Side Comparison Matrix & Awarding).
 * Each entry is [it, en, de, fr]. Placeholders are written {name}.
 */
final class RfqFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.PROC_RFQS' => [
                'Richieste di Offerta (RDO)',
                'Requests for Quotation (RFQ)',
                'Offertanfragen (RFQ)',
                'Demandes d\'offre (RFQ)',
            ],

            'RFQ.TITLE' => [
                'Richieste di Offerta (RDO)',
                'Requests for Quotation (RFQ)',
                'Offertanfragen (RFQ)',
                'Demandes d\'offre (RFQ)',
            ],
            'RFQ.SUBTITLE' => [
                'Gestisci gare multi-fornitore, confronto offerte affiancato e aggiudicazione ordini.',
                'Manage multi-supplier tenders, side-by-side bid comparison and order awarding.',
                'Verwalten Sie Lieferantenausschreibungen, Angebotsvergleiche und Zuschlagsvergaben.',
                'Gérez les appels d\'offres multi-fournisseurs, comparaisons et attributions.',
            ],
            'RFQ.NEW' => ['Nuova RDO', 'New RFQ', 'Neue Offertanfrage', 'Nouvelle RFQ'],
            'RFQ.EDIT' => ['Modifica RDO', 'Edit RFQ', 'Offertanfrage bearbeiten', 'Modifier la RFQ'],
            'RFQ.CREATE_TITLE' => ['Crea Richiesta di Offerta', 'Create Request for Quotation', 'Offertanfrage erstellen', 'Créer une demande d\'offre'],
            'RFQ.EDIT_TITLE' => ['Modifica Richiesta di Offerta', 'Edit Request for Quotation', 'Offertanfrage bearbeiten', 'Modifier la demande d\'offre'],
            'RFQ.SEARCH_PLACEHOLDER' => [
                'Cerca per numero RDO o titolo...',
                'Search by RFQ number or title...',
                'Nach RFQ-Nummer oder Titel suchen...',
                'Rechercher par numéro RFQ ou titre...',
            ],
            'RFQ.FILTER_STATUS' => ['Filtra per stato', 'Filter by status', 'Nach Status filtern', 'Filtrer par statut'],
            'RFQ.ALL_STATUSES' => ['Tutti gli stati', 'All statuses', 'Alle Status', 'Tous les statuts'],
            'RFQ.REFRESH' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'RFQ.EMPTY' => ['Nessuna richiesta di offerta trovata', 'No requests for quotation found', 'Keine Offertanfragen gefunden', 'Aucune demande d\'offre trouvée'],
            'RFQ.LOAD_ERROR' => ['Impossibile caricare le richieste di offerta', 'Unable to load requests for quotation', 'Offertanfragen konnten nicht geladen werden', 'Impossible de charger les demandes d\'offre'],

            'RFQ.STATUS_DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'RFQ.STATUS_PUBLISHED' => ['Pubblicata', 'Published', 'Veröffentlicht', 'Publiée'],
            'RFQ.STATUS_BIDDING_OPEN' => ['Gara aperta', 'Bidding Open', 'Ausschreibung offen', 'Appel d\'offres ouvert'],
            'RFQ.STATUS_ISSUED' => ['Emessa', 'Issued', 'Ausgegeben', 'Émise'],
            'RFQ.STATUS_UNDER_EVALUATION' => ['In valutazione', 'Under Evaluation', 'In Bewertung', 'En évaluation'],
            'RFQ.STATUS_AWARDED' => ['Aggiudicata', 'Awarded', 'Zugeschlagen', 'Attribuée'],
            'RFQ.STATUS_CANCELLED' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'RFQ.STATUS_EXPIRED' => ['Scaduta', 'Expired', 'Abgelaufen', 'Expirée'],

            'RFQ.COL_NUMBER' => ['Numero RDO', 'RFQ Number', 'RFQ-Nummer', 'Numéro RFQ'],
            'RFQ.COL_TITLE' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'RFQ.COL_STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'RFQ.COL_DEADLINE' => ['Scadenza offerte', 'Submission Deadline', 'Angebotsfrist', 'Date limite'],
            'RFQ.COL_RESPONSE_RATE' => ['Risposte', 'Response Rate', 'Antwortquote', 'Taux de réponse'],
            'RFQ.COL_CURRENCY' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'RFQ.COL_ACTIONS' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'RFQ.BIDS_RECEIVED_PROGRESS' => [
                '{received}/{invited} offerte ricevute',
                '{received}/{invited} Bids Received',
                '{received}/{invited} Angebote erhalten',
                '{received}/{invited} offres reçues',
            ],
            'RFQ.COUNTDOWN_DAYS' => ['{days}g {hours}h rimanenti', '{days}d {hours}h left', 'Noch {days}T {hours}Std', '{days}j {hours}h restantes'],
            'RFQ.COUNTDOWN_HOURS' => ['{hours}h {minutes}m rimanenti', '{hours}h {minutes}m left', 'Noch {hours}Std {minutes}Min', '{hours}h {minutes}m restantes'],
            'RFQ.COUNTDOWN_EXPIRED' => ['Scaduta', 'Expired', 'Abgelaufen', 'Expirée'],

            'RFQ.ACTION_OPEN_MATRIX' => ['Confronto Offerte', 'Compare Bids', 'Angebotsvergleich', 'Comparer les offres'],
            'RFQ.ACTION_PUBLISH' => ['Pubblica e Invita', 'Publish & Invite', 'Veröffentlichen & Einladen', 'Publier et inviter'],
            'RFQ.ACTION_EXTEND_DEADLINE' => ['Proroga Scadenza', 'Extend Deadline', 'Frist verlängern', 'Prolonger le délai'],
            'RFQ.ACTION_CANCEL' => ['Annulla RDO', 'Cancel RFQ', 'RFQ stornieren', 'Annuler la RFQ'],
            'RFQ.ACTION_EDIT' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'RFQ.EXTEND_MODAL_TITLE' => ['Proroga scadenza presentazione', 'Extend Submission Deadline', 'Angebotsfrist verlängern', 'Prolonger la date limite'],
            'RFQ.EXTEND_NEW_DEADLINE' => ['Nuova scadenza (UTC)', 'New Deadline (UTC)', 'Neue Frist (UTC)', 'Nouvelle échéance (UTC)'],
            'RFQ.EXTEND_SUCCESS' => ['Scadenza prorogata e fornitori notificati', 'Deadline extended and suppliers notified', 'Frist verlängert und Lieferanten benachrichtigt', 'Échéance prolongée et fournisseurs notifiés'],
            'RFQ.CANCEL_CONFIRM' => [
                'Annullare questa RDO? Le righe della richiesta di acquisto verranno sbloccate.',
                'Cancel this RFQ? Linked requisition lines will be released.',
                'Diese Offertanfrage stornieren? Verknüpfte Bestellanforderungen werden freigegeben.',
                'Annuler cette RFQ ? Les lignes de demande d\'achat liées seront libérées.',
            ],
            'RFQ.CANCEL_REASON' => ['Motivazione annullamento', 'Cancellation reason', 'Stornierungsgrund', 'Motif d\'annulation'],
            'RFQ.CANCEL_SUCCESS' => ['Richiesta di offerta annullata', 'RFQ cancelled', 'Offertanfrage storniert', 'Demande d\'offre annulée'],
            'RFQ.PUBLISH_SUCCESS' => ['RDO pubblicata e inviti inviati', 'RFQ published and invitations sent', 'Offertanfrage veröffentlicht und Einladungen versendet', 'RFQ publiée et invitations envoyées'],

            'RFQ.WIZARD.STEP_GENERAL' => ['Dati Generali & Criteri', 'General & Criteria', 'Allgemeines & Kriterien', 'Général & Critères'],
            'RFQ.WIZARD.STEP_LINES' => ['Articoli / Righe', 'Line Items', 'Positionen', 'Lignes d\'articles'],
            'RFQ.WIZARD.STEP_ATTACHMENTS' => ['Allegati Tecnici', 'Technical Attachments', 'Technische Anhänge', 'Pièces jointes techniques'],
            'RFQ.WIZARD.STEP_SUPPLIERS' => ['Selezione Fornitori', 'Supplier Selection', 'Lieferantenauswahl', 'Sélection des fournisseurs'],
            'RFQ.WIZARD.TITLE' => ['Titolo gara', 'Tender Title', 'Ausschreibungstitel', 'Titre de l\'appel d\'offres'],
            'RFQ.WIZARD.DESCRIPTION' => ['Descrizione e ambito', 'Description & Scope', 'Beschreibung & Umfang', 'Description et périmètre'],
            'RFQ.WIZARD.CURRENCY' => ['Valuta base', 'Base Currency', 'Basiswährung', 'Devise de base'],
            'RFQ.WIZARD.INCOTERMS' => ['Incoterms richiesti', 'Requested Incoterms', 'Gewünschte Incoterms', 'Incoterms demandés'],
            'RFQ.WIZARD.SUBMISSION_DEADLINE' => ['Scadenza invio offerte', 'Submission Deadline', 'Angebotsfrist', 'Date limite de soumission'],
            'RFQ.WIZARD.EXPECTED_DELIVERY' => ['Consegna prevista', 'Expected Delivery Date', 'Voraussichtlicher Liefertermin', 'Date de livraison prévue'],
            'RFQ.WIZARD.SEALED_BIDDING' => ['Busta chiusa (Sealed Bidding)', 'Sealed Bidding', 'Versiegelte Angebote (Sealed Bidding)', 'Offres scellées'],
            'RFQ.WIZARD.SEALED_BIDDING_HINT' => [
                'I prezzi unitari restano nascosti fino alla scadenza.',
                'Hide supplier unit prices until submission deadline passes.',
                'Einzelpreise bleiben bis zum Ablauf der Frist verborgen.',
                'Les prix unitaires restent masqués jusqu\'à l\'échéance.',
            ],
            'RFQ.WIZARD.WEIGHTS_TITLE' => ['Pesi di valutazione (Totale 100%)', 'Evaluation Weights (Total 100%)', 'Bewertungsgewichte (Summe 100%)', 'Pondérations d\'évaluation (Total 100 %)'],
            'RFQ.WIZARD.WEIGHT_PRICE' => ['Prezzo (%)', 'Price (%)', 'Preis (%)', 'Prix (%)'],
            'RFQ.WIZARD.WEIGHT_LEAD_TIME' => ['Tempi di consegna (%)', 'Lead Time (%)', 'Lieferzeit (%)', 'Délai de livraison (%)'],
            'RFQ.WIZARD.WEIGHT_RATING' => ['Rating fornitore (%)', 'Supplier Rating (%)', 'Lieferantenbewertung (%)', 'Note fournisseur (%)'],
            'RFQ.WIZARD.WEIGHTS_SUM_ERROR' => [
                'La somma dei pesi deve essere esattamente 100% (attuale: {sum}%).',
                'Evaluation weights must sum to 100% (current: {sum}%).',
                'Die Gewichte müssen genau 100% ergeben (aktuell: {sum}%).',
                'La somme des pondérations doit être égale à 100 % (actuel : {sum} %).',
            ],

            'RFQ.WIZARD.IMPORT_PR_BUTTON' => ['Importa da Richieste di Acquisto (Mod 044)', 'Import from Approved Purchase Requisitions (Mod 044)', 'Aus genehmigten Bestellanforderungen importieren (Mod 044)', 'Importer depuis des demandes d\'achat approuvées (Mod 044)'],
            'RFQ.WIZARD.IMPORT_PR_DRAWER_TITLE' => ['Seleziona Richieste di Acquisto Approvate', 'Select Approved Purchase Requisitions', 'Genehmigte Bestellanforderungen auswählen', 'Sélectionner des demandes d\'achat approuvées'],
            'RFQ.WIZARD.CONSOLIDATE_ITEMS' => ['Aggrega articoli identici', 'Consolidate matching items', 'Identische Positionen zusammenfassen', 'Consolider les articles identiques'],
            'RFQ.WIZARD.IMPORT_SELECTED' => ['Importa righe selezionate ({count})', 'Import selected lines ({count})', 'Ausgewählte Positionen importieren ({count})', 'Importer les lignes sélectionnées ({count})'],
            'RFQ.WIZARD.ADD_LINE' => ['Aggiungi riga', 'Add Line', 'Position hinzufügen', 'Ajouter une ligne'],
            'RFQ.WIZARD.REMOVE_LINE' => ['Rimuovi', 'Remove', 'Entfernen', 'Supprimer'],
            'RFQ.WIZARD.LINE_CODE' => ['Codice articolo / SKU', 'Item Code / SKU', 'Artikelnummer / SKU', 'Code article / SKU'],
            'RFQ.WIZARD.LINE_DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'RFQ.WIZARD.LINE_SPECS' => ['Specifiche tecniche', 'Technical Specifications', 'Technische Spezifikationen', 'Spécifications techniques'],
            'RFQ.WIZARD.LINE_QTY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'RFQ.WIZARD.LINE_UOM' => ['UdM', 'UoM', 'ME', 'UdM'],
            'RFQ.WIZARD.LINE_TARGET_PRICE' => ['Prezzo target', 'Target Price', 'Zielpreis', 'Prix cible'],
            'RFQ.WIZARD.LINE_DELIVERY_DATE' => ['Data richiesta', 'Requested Date', 'Wunschtermin', 'Date souhaitée'],

            'RFQ.WIZARD.UPLOAD_HINT' => [
                'Carica disegni tecnici, distinte base (BOM) o specifiche PDF/CAD.',
                'Upload technical specifications, drawings, or BOMs (PDF/CAD/Office).',
                'Technische Spezifikationen, Zeichnungen oder Stücklisten hochladen.',
                'Téléversez des spécifications techniques, plans ou nomenclatures (BOM).',
            ],
            'RFQ.WIZARD.VISIBLE_TO_SUPPLIERS' => ['Visibile ai fornitori', 'Visible to Suppliers', 'Für Lieferanten sichtbar', 'Visible par les fournisseurs'],
            'RFQ.WIZARD.SUPPLIER_SEARCH' => ['Cerca fornitori qualificati...', 'Search qualified suppliers...', 'Qualifizierte Lieferanten suchen...', 'Rechercher des fournisseurs qualifiés...'],
            'RFQ.WIZARD.SUPPLIER_CATEGORY' => ['Categoria fornitore', 'Supplier Category', 'Lieferantenkategorie', 'Catégorie de fournisseur'],
            'RFQ.WIZARD.SELECTED_SUPPLIERS' => ['{count} fornitori selezionati', '{count} suppliers selected', '{count} Lieferanten ausgewählt', '{count} fournisseurs sélectionnés'],
            'RFQ.WIZARD.SAVE_DRAFT' => ['Salva Bozza', 'Save Draft', 'Entwurf speichern', 'Enregistrer le brouillon'],
            'RFQ.WIZARD.PUBLISH_DISPATCH' => ['Pubblica e Invia Inviti', 'Publish & Send Invitations', 'Veröffentlichen & Einladungen senden', 'Publier et envoyer les invitations'],
            'RFQ.WIZARD.PREV' => ['Indietro', 'Previous', 'Zurück', 'Précédent'],
            'RFQ.WIZARD.NEXT' => ['Avanti', 'Next', 'Weiter', 'Suivant'],
            'RFQ.WIZARD.SAVED_DRAFT' => ['Bozza RDO salvata', 'RFQ draft saved', 'RFQ-Entwurf gespeichert', 'Brouillon RFQ enregistré'],

            'RFQ.MATRIX.TITLE' => ['Matrice di Confronto Offerte', 'Side-by-Side Bid Comparison Matrix', 'Angebotsvergleichsmatrix', 'Matrice de comparaison des offres'],
            'RFQ.MATRIX.SEALED_BANNER' => [
                'Gara a busta chiusa attiva: i prezzi unitari e i punteggi saranno visibili dopo il {deadline}.',
                'Sealed bidding active: unit prices and scores remain hidden until {deadline}.',
                'Versiegeltes Bieterverfahren aktiv: Preise und Bewertungen sind erst nach {deadline} sichtbar.',
                'Offres scellées actives : les prix unitaires et notes seront visibles après le {deadline}.',
            ],
            'RFQ.MATRIX.PRESET_LOWEST_TOTAL' => ['Costo Totale Minore (Fornitore Unico)', 'Lowest Total Cost (Single Supplier)', 'Geringste Gesamtkosten (Einzelanbieter)', 'Coût total le plus bas (Fournisseur unique)'],
            'RFQ.MATRIX.PRESET_OPTIMAL_SPLIT' => ['Aggiudicazione Divisa Ottimale (Punteggio Max)', 'Optimal Split Award (Best Weighted Score per Line)', 'Optimale Teilvergabe (Beste Bewertung je Position)', 'Attribution fractionnée optimale (Meilleur score)'],
            'RFQ.MATRIX.PRESET_LOWEST_PRICE_SPLIT' => ['Prezzo Minore per Riga (Divisa)', 'Lowest Price per Line (Split)', 'Niedrigster Preis je Position (Geteilt)', 'Prix le plus bas par ligne (Fractionné)'],
            'RFQ.MATRIX.BADGE_BEST_PRICE' => ['Miglior Prezzo', 'Best Price', 'Bester Preis', 'Meilleur prix'],
            'RFQ.MATRIX.BADGE_FASTEST' => ['Più Veloce', 'Fastest', 'Schnellste', 'Plus rapide'],
            'RFQ.MATRIX.BADGE_TOP_SCORE' => ['Punteggio Top', 'Top Score', 'Bestnote', 'Meilleur score'],
            'RFQ.MATRIX.NO_BID' => ['Nessuna offerta', 'No Bid', 'Kein Angebot', 'Sans offre'],
            'RFQ.MATRIX.UNIT_PRICE' => ['Prezzo Unitario', 'Unit Price', 'Einzelpreis', 'Prix unitaire'],
            'RFQ.MATRIX.DISCOUNT' => ['Sconto', 'Discount', 'Rabatt', 'Remise'],
            'RFQ.MATRIX.NET_TOTAL' => ['Totale Netto', 'Net Total', 'Nettosumme', 'Total net'],
            'RFQ.MATRIX.LEAD_TIME_DAYS' => ['{days} gg', '{days} days', '{days} Tage', '{days} jours'],
            'RFQ.MATRIX.SCORE' => ['Punteggio', 'Score', 'Punktzahl', 'Score'],
            'RFQ.MATRIX.LANDED_COST' => ['Costo Totale (TCO)', 'Total Landed Cost', 'Gesamtkosten (Landed)', 'Coût total rendu'],
            'RFQ.MATRIX.OVERALL_SCORE' => ['Punteggio Globale', 'Overall Weighted Score', 'Gesamtbewertung', 'Score global pondéré'],
            'RFQ.MATRIX.PAYMENT_TERMS' => ['Pagamento: {days} gg', 'Terms: {days}d', 'Zahlungsziel: {days}T', 'Conditions : {days}j'],
            'RFQ.MATRIX.SELECT_LINE_FOR_AWARD' => ['Seleziona per aggiudicazione', 'Select for award', 'Für Zuschlag wählen', 'Sélectionner pour attribution'],
            'RFQ.MATRIX.REVIEW_AWARD' => ['Rivedi e Aggiudica ({count} righe)', 'Review & Award ({count} lines)', 'Prüfen & Vergeben ({count} Positionen)', 'Vérifier et attribuer ({count} lignes)'],
            'RFQ.MATRIX.AWARD_DRAWER_TITLE' => ['Conferma Aggiudicazione e Generazione Ordini', 'Award Confirmation & Purchase Order Preview', 'Zuschlagsbestätigung & Bestellvorschau', 'Confirmation d\'attribution et aperçu des commandes'],
            'RFQ.MATRIX.PO_PREVIEW_SUPPLIER' => ['Ordine d\'acquisto per {supplier}', 'Draft Purchase Order for {supplier}', 'Bestellentwurf für {supplier}', 'Commande d\'achat pour {supplier}'],
            'RFQ.MATRIX.EMAIL_PREVIEW_TITLE' => ['Notifiche Email Automatiche', 'Automated Email Notifications', 'Automatische E-Mail-Benachrichtigungen', 'Notifications e-mail automatiques'],
            'RFQ.MATRIX.EMAIL_WINNERS' => ['{count} email di aggiudicazione ai fornitori vincitori', '{count} Award email(s) to winning supplier(s)', '{count} Zuschlags-E-Mail(s) an Gewinner', '{count} e-mail(s) d\'attribution aux gagnants'],
            'RFQ.MATRIX.EMAIL_REGRET' => ['{count} email di esito negativo agli altri partecipanti', '{count} Regret email(s) to non-awarded participants', '{count} Absage-E-Mail(s) an übrige Teilnehmer', '{count} e-mail(s) de regret aux autres participants'],
            'RFQ.MATRIX.CONFIRM_AWARD' => ['Conferma Aggiudicazione', 'Confirm Award', 'Zuschlag bestätigen', 'Confirmer l\'attribution'],
            'RFQ.MATRIX.AWARD_SUCCESS' => ['RDO aggiudicata con successo. Ordini generati: {count}', 'RFQ awarded successfully. Purchase Orders generated: {count}', 'Offertanfrage erfolgreich vergeben. Bestellungen erstellt: {count}', 'RFQ attribuée avec succès. Commandes créées : {count}'],
            'RFQ.MATRIX.NO_BIDS_YET' => ['Nessuna offerta ricevuta finora dai fornitori invitati.', 'No supplier bids have been submitted yet.', 'Es liegen noch keine Lieferantenangebote vor.', 'Aucune offre n\'a encore été soumise par les fournisseurs.'],

            'RFQ.PORTAL.TITLE' => ['Portale Offerte Fornitore', 'Supplier Bidding Portal', 'Lieferanten-Angebotsportal', 'Portail de soumission fournisseur'],
            'RFQ.PORTAL.LANGUAGE' => ['Lingua', 'Language', 'Sprache', 'Langue'],
            'RFQ.PORTAL.BUYER_HEADER' => ['Emesso da {company}', 'Issued by {company}', 'Ausgestellt von {company}', 'Émis par {company}'],
            'RFQ.PORTAL.INVALID_OR_EXPIRED' => [
                'Questo collegamento non è valido, è scaduto oppure la richiesta è stata annullata.',
                'This bidding link is invalid, expired, or the RFQ has been cancelled.',
                'Dieser Angebotslink ist ungültig, abgelaufen oder die Anfrage wurde storniert.',
                'Ce lien de soumission est invalide, expiré ou l\'appel d\'offres a été annulé.',
            ],
            'RFQ.PORTAL.ATTACHMENTS_TITLE' => ['Documenti Tecnici e Specifiche', 'Technical Specifications & Drawings', 'Technische Unterlagen & Zeichnungen', 'Spécifications techniques et plans'],
            'RFQ.PORTAL.DOWNLOAD' => ['Scarica', 'Download', 'Herunterladen', 'Télécharger'],
            'RFQ.PORTAL.QUOTE_HEADER' => ['Condizioni Commerciali dell\'Offerta', 'Commercial Quotation Terms', 'Kommerzielle Angebotsbedingungen', 'Conditions commerciales de l\'offre'],
            'RFQ.PORTAL.QUOTE_REFERENCE' => ['Vs. Riferimento Offerta', 'Your Quote Reference', 'Ihre Angebotsreferenz', 'Votre référence d\'offre'],
            'RFQ.PORTAL.CURRENCY' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'RFQ.PORTAL.PAYMENT_TERMS_DAYS' => ['Termini di pagamento (giorni)', 'Payment Terms (days)', 'Zahlungsziel (Tage)', 'Conditions de paiement (jours)'],
            'RFQ.PORTAL.INCOTERMS' => ['Incoterms', 'Incoterms', 'Incoterms', 'Incoterms'],
            'RFQ.PORTAL.SHIPPING_COST' => ['Spese di spedizione', 'Shipping Cost', 'Versandkosten', 'Frais de port'],
            'RFQ.PORTAL.VALID_UNTIL' => ['Offerta valida fino al', 'Valid Until', 'Gültig bis', 'Valide jusqu\'au'],
            'RFQ.PORTAL.GENERAL_NOTES' => ['Note generali', 'General Notes', 'Allgemeine Bemerkungen', 'Remarques générales'],
            'RFQ.PORTAL.QUOTE_PDF' => ['Allega Offerta PDF (opzionale)', 'Attach Quote PDF (optional)', 'Angebots-PDF anhängen (optional)', 'Joindre l\'offre PDF (optionnel)'],
            'RFQ.PORTAL.COL_NO_BID' => ['Non offro', 'No Bid', 'Kein Angebot', 'Sans offre'],
            'RFQ.PORTAL.COL_UNIT_PRICE' => ['Prezzo Unitario', 'Unit Price', 'Einzelpreis', 'Prix unitaire'],
            'RFQ.PORTAL.COL_DISCOUNT' => ['Sconto %', 'Discount %', 'Rabatt %', 'Remise %'],
            'RFQ.PORTAL.COL_LEAD_TIME' => ['Consegna (gg)', 'Lead Time (days)', 'Lieferzeit (Tage)', 'Délai (jours)'],
            'RFQ.PORTAL.COL_LINE_NET' => ['Totale Riga', 'Line Net Total', 'Positionsnetto', 'Total ligne HT'],
            'RFQ.PORTAL.COL_NOTES' => ['Note riga', 'Line Notes', 'Positionsnotiz', 'Notes ligne'],
            'RFQ.PORTAL.SUMMARY_NET' => ['Totale Netto Articoli', 'Items Net Total', 'Nettosumme Positionen', 'Total HT articles'],
            'RFQ.PORTAL.SUMMARY_LANDED' => ['Totale Complessivo (incl. spedizione)', 'Total Landed Offer (incl. shipping)', 'Gesamtangebot (inkl. Versand)', 'Total offre (port inclus)'],
            'RFQ.PORTAL.SAVE_DRAFT' => ['Salva Bozza', 'Save Draft', 'Entwurf speichern', 'Enregistrer le brouillon'],
            'RFQ.PORTAL.SUBMIT_BID' => ['Invia Offerta Finale', 'Submit Final Quote', 'Endgültiges Angebot absenden', 'Soumettre l\'offre finale'],
            'RFQ.PORTAL.DECLINE_BUTTON' => ['Rifiuta di partecipare', 'Decline to Bid', 'Teilnahme ablehnen', 'Décliner l\'invitation'],
            'RFQ.PORTAL.DECLINE_MODAL_TITLE' => ['Rifiuta partecipazione alla RDO', 'Decline RFQ Participation', 'Teilnahme an Offertanfrage ablehnen', 'Décliner la participation'],
            'RFQ.PORTAL.DECLINE_REASON' => ['Motivazione (opzionale)', 'Reason (optional)', 'Grund (optional)', 'Motif (optionnel)'],
            'RFQ.PORTAL.DECLINE_CONFIRM' => ['Conferma Rifiuto', 'Confirm Decline', 'Ablehnung bestätigen', 'Confirmer le refus'],
            'RFQ.PORTAL.SAVED_DRAFT_OK' => ['Bozza offerta salvata', 'Quote draft saved', 'Angebotsentwurf gespeichert', 'Brouillon d\'offre enregistré'],
            'RFQ.PORTAL.SUBMITTED_OK' => ['Offerta inviata con successo! Riceverai una conferma via email.', 'Quote submitted successfully! Thank you for participating.', 'Angebot erfolgreich übermittelt! Vielen Dank für Ihre Teilnahme.', 'Offre soumise avec succès ! Merci pour votre participation.'],
            'RFQ.PORTAL.DECLINED_OK' => ['Hai rifiutato la partecipazione a questa richiesta di offerta.', 'You have declined to bid on this Request for Quotation.', 'Sie haben die Teilnahme an dieser Offertanfrage abgelehnt.', 'Vous avez décliné la participation à cette demande d\'offre.'],
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
