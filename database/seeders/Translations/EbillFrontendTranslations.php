<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the Swiss eBill module (Mod 025, Issue #283): the SIX Paynet / PostFinance settings panel, the participant directory badge
 * and lookup of the customers, the "Send via eBill" action and lifecycle timeline of the invoices, the monitoring dashboard, the standard debtor
 * rejection reasons, the pre-flight violations and the codes the API reports (swissEbill.error.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI) and the network names (SIX Paynet, PostFinance, eBill, camt.054) stay untranslated.
 */
final class EbillFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::rejectionReasons(),
            self::violations(),
            self::errors(),
            self::fields(),
            self::actions(),
            self::sections(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.SWISS_EBILL' => ['eBill svizzero', 'Swiss eBill', 'Schweizer eBill', 'eBill suisse'],
            'NAV.SWISS_EBILL_DASHBOARD' => ['Monitoraggio eBill', 'eBill monitoring', 'eBill-Überwachung', 'Suivi eBill'],
            'NAV.SWISS_EBILL_SETTINGS' => ['Configurazione eBill', 'eBill configuration', 'eBill-Konfiguration', 'Configuration eBill'],
            'PAGE_TITLE.SWISS_EBILL_DASHBOARD' => ['Monitoraggio eBill: trasmissioni SIX Paynet / PostFinance', 'eBill monitoring: SIX Paynet / PostFinance transmissions', 'eBill-Überwachung: Übermittlungen über SIX Paynet / PostFinance', 'Suivi eBill : transmissions SIX Paynet / PostFinance'],
            'PAGE_TITLE.SWISS_EBILL_SETTINGS' => ['eBill svizzero: configurazione SIX Paynet / PostFinance', 'Swiss eBill: SIX Paynet / PostFinance configuration', 'Schweizer eBill: Konfiguration SIX Paynet / PostFinance', 'eBill suisse : configuration SIX Paynet / PostFinance'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            // Transmission status: label and description
            'swissEbill.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'swissEbill.status.queued' => ['In coda', 'Queued', 'In Warteschlange', 'En file d’attente'],
            'swissEbill.status.transmitted' => ['Trasmessa', 'Transmitted', 'Übermittelt', 'Transmise'],
            'swissEbill.status.delivered_to_ebanking' => ['Recapitata nell’e-banking', 'Delivered to e-banking', 'Im E-Banking zugestellt', 'Remise dans l’e-banking'],
            'swissEbill.status.approved_by_debtor' => ['Approvata dal cliente per il pagamento', 'Approved by the customer for payment', 'Vom Kunden zur Zahlung freigegeben', 'Approuvée par le client pour paiement'],
            'swissEbill.status.rejected_by_debtor' => ['Rifiutata', 'Rejected', 'Abgelehnt', 'Refusée'],
            'swissEbill.status.failed_technical' => ['Errore tecnico di trasmissione', 'Technical transmission error', 'Technischer Übermittlungsfehler', 'Erreur technique de transmission'],
            'swissEbill.status.paid_reconciled' => ['Pagata', 'Paid', 'Bezahlt', 'Payée'],
            'swissEbill.status.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'swissEbill.statusHint.draft' => ['Preparata localmente, non ancora in coda.', 'Prepared locally, not queued yet.', 'Lokal vorbereitet, noch nicht eingereiht.', 'Préparée localement, pas encore en file.'],
            'swissEbill.statusHint.queued' => ['In attesa di essere consegnata al gateway.', 'Waiting to be handed to the gateway.', 'Wartet auf die Übergabe an das Gateway.', 'En attente de remise à la passerelle.'],
            'swissEbill.statusHint.transmitted' => ['Accettata dal gateway SIX Paynet / PostFinance.', 'Accepted by the SIX Paynet / PostFinance gateway.', 'Vom Gateway SIX Paynet / PostFinance angenommen.', 'Acceptée par la passerelle SIX Paynet / PostFinance.'],
            'swissEbill.statusHint.delivered_to_ebanking' => ['Visibile nel portale e-banking del cliente.', 'Visible in the e-banking portal of the customer.', 'Im E-Banking-Portal des Kunden sichtbar.', 'Visible dans le portail e-banking du client.'],
            'swissEbill.statusHint.approved_by_debtor' => ['Il cliente ha approvato il pagamento: esecuzione alla data indicata.', 'The customer approved the payment: it is executed on the scheduled date.', 'Der Kunde hat die Zahlung freigegeben: Ausführung am vorgesehenen Datum.', 'Le client a approuvé le paiement : exécution à la date prévue.'],
            'swissEbill.statusHint.rejected_by_debtor' => ['Il cliente ha rifiutato la fattura: correggi e reinvia.', 'The customer rejected the invoice: correct it and send it again.', 'Der Kunde hat die Rechnung abgelehnt: korrigieren und erneut senden.', 'Le client a refusé la facture : corrigez et renvoyez.'],
            'swissEbill.statusHint.failed_technical' => ['Il gateway o la rete ha rifiutato la trasmissione: puoi riprovare.', 'The gateway or the network refused the transmission: you can try again.', 'Das Gateway oder das Netz hat die Übermittlung abgelehnt: Sie können es erneut versuchen.', 'La passerelle ou le réseau a refusé la transmission : vous pouvez réessayer.'],
            'swissEbill.statusHint.paid_reconciled' => ['Incasso riconciliato con la notifica di accredito camt.054.', 'Settled and reconciled with the camt.054 credit notification.', 'Mit der camt.054-Gutschriftsanzeige abgeglichen und beglichen.', 'Encaissement rapproché avec la notification de crédit camt.054.'],
            'swissEbill.statusHint.cancelled' => ['Richiamata prima dell’approvazione del cliente.', 'Recalled before the customer approved it.', 'Vor der Freigabe durch den Kunden zurückgerufen.', 'Rappelée avant l’approbation du client.'],

            // Customer directory status and badges
            'swissEbill.participant.unknown' => ['Non verificato', 'Not verified', 'Nicht geprüft', 'Non vérifié'],
            'swissEbill.participant.enrolled' => ['Iscritto a eBill', 'Enrolled in eBill', 'Bei eBill angemeldet', 'Inscrit à eBill'],
            'swissEbill.participant.not_enrolled' => ['Non iscritto', 'Not enrolled', 'Nicht angemeldet', 'Non inscrit'],
            'swissEbill.participant.pending_opt_in' => ['Consenso in attesa', 'Opt-in pending', 'Zustimmung ausstehend', 'Consentement en attente'],
            'swissEbill.participant.suspended' => ['Sospeso', 'Suspended', 'Gesperrt', 'Suspendu'],
            'swissEbill.badge.active_b2b' => ['eBill attivo B2B', 'eBill Active B2B', 'eBill aktiv B2B', 'eBill actif B2B'],
            'swissEbill.badge.active_b2c' => ['eBill attivo B2C', 'eBill Active B2C', 'eBill aktiv B2C', 'eBill actif B2C'],
            'swissEbill.badge.not_enrolled' => ['Non iscritto', 'Not Enrolled', 'Nicht angemeldet', 'Non inscrit'],
            'swissEbill.badge.not_verified' => ['Non verificato', 'Not Verified', 'Nicht geprüft', 'Non vérifié'],
            'swissEbill.badge.pending_opt_in' => ['eBill in attesa di consenso', 'eBill opt-in pending', 'eBill Zustimmung ausstehend', 'eBill consentement en attente'],
            'swissEbill.badge.suspended' => ['eBill sospeso', 'eBill suspended', 'eBill gesperrt', 'eBill suspendu'],
            'swissEbill.channel.b2c_private' => ['B2C privato', 'B2C private', 'B2C Privat', 'B2C privé'],
            'swissEbill.channel.b2b_enterprise' => ['B2B azienda', 'B2B enterprise', 'B2B Unternehmen', 'B2B entreprise'],

            // Configuration options
            'swissEbill.provider.six_paynet_direct' => ['SIX Paynet (diretto)', 'SIX Paynet (direct)', 'SIX Paynet (direkt)', 'SIX Paynet (direct)'],
            'swissEbill.provider.postfinance_ebill' => ['PostFinance eBill', 'PostFinance eBill', 'PostFinance eBill', 'PostFinance eBill'],
            'swissEbill.provider.sandbox_simulator' => ['Simulatore sandbox', 'Sandbox simulator', 'Sandbox-Simulator', 'Simulateur sandbox'],
            'swissEbill.protocol.rest_json_xml' => ['REST (JSON / XML)', 'REST (JSON / XML)', 'REST (JSON / XML)', 'REST (JSON / XML)'],
            'swissEbill.protocol.soap_ebinterface' => ['SOAP (ebInterface)', 'SOAP (ebInterface)', 'SOAP (ebInterface)', 'SOAP (ebInterface)'],
            'swissEbill.webhookAuth.hmac_sha256' => ['HMAC-SHA256', 'HMAC-SHA256', 'HMAC-SHA256', 'HMAC-SHA256'],
            'swissEbill.webhookAuth.jws_hs256' => ['JWS (HS256)', 'JWS (HS256)', 'JWS (HS256)', 'JWS (HS256)'],
            'swissEbill.webhookAuth.mutual_tls' => ['TLS reciproco (mTLS)', 'Mutual TLS (mTLS)', 'Gegenseitiges TLS (mTLS)', 'TLS mutuel (mTLS)'],
            'swissEbill.version.ebinterface61' => ['ebInterface 6.1', 'ebInterface 6.1', 'ebInterface 6.1', 'ebInterface 6.1'],
            'swissEbill.version.sixXml' => ['SIX eBill XML', 'SIX eBill XML', 'SIX eBill XML', 'SIX eBill XML'],
            'swissEbill.connection.untested' => ['Non testata', 'Not tested', 'Nicht getestet', 'Non testée'],
            'swissEbill.connection.connected' => ['Connessa', 'Connected', 'Verbunden', 'Connectée'],
            'swissEbill.connection.failed' => ['Connessione non riuscita', 'Connection failed', 'Verbindung fehlgeschlagen', 'Connexion échouée'],
            'swissEbill.lookupType.uid' => ['UID', 'UID', 'UID', 'IDE'],
            'swissEbill.lookupType.email' => ['Email', 'Email', 'E-Mail', 'E-mail'],
            'swissEbill.lookupType.iban' => ['IBAN / QR-IBAN', 'IBAN / QR-IBAN', 'IBAN / QR-IBAN', 'IBAN / QR-IBAN'],
            'swissEbill.lookupType.participant_id' => ['ID partecipante eBill', 'eBill participant ID', 'eBill-Teilnehmer-ID', 'ID de participant eBill'],

            // Timeline events and sources
            'swissEbill.event.prepared' => ['Payload SIX preparato e validato', 'SIX payload prepared and validated', 'SIX-Payload vorbereitet und validiert', 'Charge SIX préparée et validée'],
            'swissEbill.event.queued' => ['Messa in coda per la trasmissione', 'Queued for transmission', 'Zur Übermittlung eingereiht', 'Mise en file pour transmission'],
            'swissEbill.event.transmitted' => ['Accettata dal gateway', 'Accepted by the gateway', 'Vom Gateway angenommen', 'Acceptée par la passerelle'],
            'swissEbill.event.delivered_to_ebanking' => ['Recapitata nell’e-banking del cliente', 'Delivered to the e-banking of the customer', 'Im E-Banking des Kunden zugestellt', 'Remise dans l’e-banking du client'],
            'swissEbill.event.approved_by_debtor' => ['Approvata dal cliente per il pagamento', 'Approved by the customer for payment', 'Vom Kunden zur Zahlung freigegeben', 'Approuvée par le client pour paiement'],
            'swissEbill.event.rejected_by_debtor' => ['Rifiutata dal cliente', 'Rejected by the customer', 'Vom Kunden abgelehnt', 'Refusée par le client'],
            'swissEbill.event.failed_technical' => ['Errore tecnico di trasmissione', 'Technical transmission error', 'Technischer Übermittlungsfehler', 'Erreur technique de transmission'],
            'swissEbill.event.paid_reconciled' => ['Pagata e riconciliata (camt.054)', 'Paid and reconciled (camt.054)', 'Bezahlt und abgeglichen (camt.054)', 'Payée et rapprochée (camt.054)'],
            'swissEbill.event.cancelled' => ['Richiamata / annullata', 'Recalled / cancelled', 'Zurückgerufen / storniert', 'Rappelée / annulée'],
            'swissEbill.event.retry_scheduled' => ['Nuovo tentativo pianificato', 'Retry scheduled', 'Neuer Versuch geplant', 'Nouvelle tentative planifiée'],
            'swissEbill.event.retry_requested' => ['Nuovo invio richiesto', 'Resend requested', 'Erneuter Versand angefordert', 'Nouvel envoi demandé'],
            'swissEbill.event.partial_settlement' => ['Pagamento parziale ricevuto', 'Partial payment received', 'Teilzahlung erhalten', 'Paiement partiel reçu'],
            'swissEbill.event.payload_downloaded' => ['Payload XML scaricato per audit', 'XML payload downloaded for audit', 'XML-Payload für Audit heruntergeladen', 'Charge XML téléchargée pour audit'],
            'swissEbill.source.system' => ['Sistema', 'System', 'System', 'Système'],
            'swissEbill.source.user' => ['Utente', 'User', 'Benutzer', 'Utilisateur'],
            'swissEbill.source.gateway_webhook' => ['Webhook del gateway', 'Gateway webhook', 'Gateway-Webhook', 'Webhook de la passerelle'],
            'swissEbill.source.gateway_poll' => ['Verifica periodica del gateway', 'Gateway polling', 'Gateway-Abfrage', 'Interrogation de la passerelle'],
            'swissEbill.source.camt054' => ['Accredito camt.054', 'camt.054 credit', 'camt.054-Gutschrift', 'Crédit camt.054'],

            // KPIs of the dashboard
            'swissEbill.kpi.total_transmitted' => ['Totale trasmesse', 'Total transmitted', 'Total übermittelt', 'Total transmises'],
            'swissEbill.kpi.awaiting_approval' => ['In attesa di approvazione', 'Awaiting debtor approval', 'Warten auf Freigabe', 'En attente d’approbation'],
            'swissEbill.kpi.approved' => ['Approvate (pagamento programmato)', 'Approved (scheduled for payout)', 'Freigegeben (Zahlung geplant)', 'Approuvées (paiement planifié)'],
            'swissEbill.kpi.rejected' => ['Rifiutate (azione richiesta)', 'Rejected (action required)', 'Abgelehnt (Handlung erforderlich)', 'Refusées (action requise)'],
            'swissEbill.kpi.reconciled' => ['Riconciliate con camt.054', 'Reconciled via camt.054', 'Per camt.054 abgeglichen', 'Rapprochées via camt.054'],
            'swissEbill.kpi.failed' => ['Errori tecnici', 'Technical errors', 'Technische Fehler', 'Erreurs techniques'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function rejectionReasons(): array
    {
        return [
            'swissEbill.rejection.AMOUNT_MISMATCH.title' => ['Importo non corrispondente', 'Amount does not match', 'Betrag stimmt nicht', 'Montant non conforme'],
            'swissEbill.rejection.AMOUNT_MISMATCH.hint' => ['L’importo non corrisponde all’ordine o al contratto: verifica prezzi e quantità.', 'The amount does not match the order or the contract: check prices and quantities.', 'Der Betrag entspricht nicht der Bestellung oder dem Vertrag: Preise und Mengen prüfen.', 'Le montant ne correspond pas à la commande ou au contrat : vérifiez prix et quantités.'],
            'swissEbill.rejection.NOT_ORDERED.title' => ['Merce o servizio non ordinato', 'Not ordered', 'Nicht bestellt', 'Non commandé'],
            'swissEbill.rejection.NOT_ORDERED.hint' => ['Il cliente dichiara di non aver ordinato: verifica ordine e destinatario.', 'The customer says nothing was ordered: check the order and the recipient.', 'Der Kunde gibt an, nichts bestellt zu haben: Bestellung und Empfänger prüfen.', 'Le client déclare ne rien avoir commandé : vérifiez la commande et le destinataire.'],
            'swissEbill.rejection.DUPLICATE.title' => ['Fattura duplicata', 'Duplicate invoice', 'Doppelte Rechnung', 'Facture en double'],
            'swissEbill.rejection.DUPLICATE.hint' => ['La fattura risulta già ricevuta o pagata: controlla gli invii precedenti.', 'The invoice was already received or paid: check the earlier deliveries.', 'Die Rechnung wurde bereits empfangen oder bezahlt: frühere Zustellungen prüfen.', 'La facture a déjà été reçue ou payée : vérifiez les envois précédents.'],
            'swissEbill.rejection.WRONG_RECIPIENT.title' => ['Destinatario errato', 'Wrong recipient', 'Falscher Empfänger', 'Destinataire erroné'],
            'swissEbill.rejection.WRONG_RECIPIENT.hint' => ['La fattura è stata recapitata a un destinatario sbagliato: aggiorna il collegamento eBill del cliente.', 'The invoice reached the wrong recipient: update the eBill link of the customer.', 'Die Rechnung ging an den falschen Empfänger: eBill-Verknüpfung des Kunden aktualisieren.', 'La facture est arrivée au mauvais destinataire : mettez à jour le lien eBill du client.'],
            'swissEbill.rejection.PO_REFERENCE_MISSING.title' => ['Riferimento d’ordine mancante', 'Purchase order reference missing', 'Bestellreferenz fehlt', 'Référence de commande manquante'],
            'swissEbill.rejection.PO_REFERENCE_MISSING.hint' => ['Il cliente richiede un numero d’ordine sulla fattura: aggiungilo e reinvia.', 'The customer needs a purchase order number on the invoice: add it and send again.', 'Der Kunde verlangt eine Bestellnummer auf der Rechnung: ergänzen und erneut senden.', 'Le client exige un numéro de commande sur la facture : ajoutez-le et renvoyez.'],
            'swissEbill.rejection.VAT_ERROR.title' => ['Errore IVA', 'VAT error', 'MWST-Fehler', 'Erreur de TVA'],
            'swissEbill.rejection.VAT_ERROR.hint' => ['Aliquota o numero IVA contestati: verifica i dati fiscali.', 'VAT rate or number disputed: check the tax data.', 'MWST-Satz oder -Nummer beanstandet: Steuerdaten prüfen.', 'Taux ou numéro de TVA contesté : vérifiez les données fiscales.'],
            'swissEbill.rejection.DELIVERY_NOT_RECEIVED.title' => ['Consegna non ricevuta', 'Delivery not received', 'Lieferung nicht erhalten', 'Livraison non reçue'],
            'swissEbill.rejection.DELIVERY_NOT_RECEIVED.hint' => ['Il cliente non ha ricevuto la merce o il servizio: verifica la consegna.', 'The customer did not receive the goods or the service: check the delivery.', 'Der Kunde hat Ware oder Leistung nicht erhalten: Lieferung prüfen.', 'Le client n’a pas reçu la marchandise ou le service : vérifiez la livraison.'],
            'swissEbill.rejection.ALREADY_PAID.title' => ['Già pagata', 'Already paid', 'Bereits bezahlt', 'Déjà payée'],
            'swissEbill.rejection.ALREADY_PAID.hint' => ['Il cliente dichiara di aver già pagato: controlla gli accrediti recenti.', 'The customer says it has already paid: check the recent credits.', 'Der Kunde gibt an, bereits bezahlt zu haben: letzte Gutschriften prüfen.', 'Le client déclare avoir déjà payé : vérifiez les crédits récents.'],
            'swissEbill.rejection.DOCUMENT_UNREADABLE.title' => ['Documento illeggibile', 'Document unreadable', 'Dokument nicht lesbar', 'Document illisible'],
            'swissEbill.rejection.DOCUMENT_UNREADABLE.hint' => ['Il PDF allegato non è leggibile: rigenera e reinvia.', 'The attached PDF cannot be read: regenerate it and send again.', 'Das beigelegte PDF ist nicht lesbar: neu erzeugen und erneut senden.', 'Le PDF joint est illisible : régénérez-le et renvoyez.'],
            'swissEbill.rejection.OTHER.title' => ['Altro motivo', 'Other reason', 'Anderer Grund', 'Autre motif'],
            'swissEbill.rejection.OTHER.hint' => ['Leggi la nota del cliente e contattalo se serve.', 'Read the note of the customer and contact them if needed.', 'Lesen Sie die Notiz des Kunden und kontaktieren Sie ihn bei Bedarf.', 'Lisez la note du client et contactez-le si nécessaire.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function violations(): array
    {
        return [
            'swissEbill.violation.ebill_disabled' => ['L’eBill non è attivo: abilitalo nella configurazione.', 'eBill is not enabled: switch it on in the configuration.', 'eBill ist nicht aktiv: in der Konfiguration einschalten.', 'eBill n’est pas activé : activez-le dans la configuration.'],
            'swissEbill.violation.biller_id_missing' => ['Biller ID mancante nella configurazione.', 'Biller ID missing in the configuration.', 'Biller-ID fehlt in der Konfiguration.', 'Biller ID manquant dans la configuration.'],
            'swissEbill.violation.biller_id_invalid' => ['Il Biller ID non è valido (17 cifre con cifra di controllo).', 'The Biller ID is not valid (17 digits with check digit).', 'Die Biller-ID ist ungültig (17 Ziffern mit Prüfziffer).', 'Le Biller ID n’est pas valide (17 chiffres avec chiffre de contrôle).'],
            'swissEbill.violation.invoice_not_issued' => ['La fattura non è ancora emessa.', 'The invoice is not issued yet.', 'Die Rechnung ist noch nicht ausgestellt.', 'La facture n’est pas encore émise.'],
            'swissEbill.violation.currency_unsupported' => ['L’eBill ammette solo CHF ed EUR.', 'eBill only accepts CHF and EUR.', 'eBill akzeptiert nur CHF und EUR.', 'eBill n’accepte que CHF et EUR.'],
            'swissEbill.violation.due_date_missing' => ['Scadenza della fattura mancante.', 'Invoice due date missing.', 'Fälligkeitsdatum der Rechnung fehlt.', 'Échéance de la facture manquante.'],
            'swissEbill.violation.lines_missing' => ['La fattura non ha righe.', 'The invoice has no lines.', 'Die Rechnung hat keine Positionen.', 'La facture n’a aucune ligne.'],
            'swissEbill.violation.vat_rate_unsupported' => ['Una riga ha un’aliquota IVA non ammessa (8.1%, 2.6%, 3.8%, 0%).', 'A line has a VAT rate that is not allowed (8.1%, 2.6%, 3.8%, 0%).', 'Eine Position hat einen nicht zulässigen MWST-Satz (8.1 %, 2.6 %, 3.8 %, 0 %).', 'Une ligne a un taux de TVA non admis (8,1 %, 2,6 %, 3,8 %, 0 %).'],
            'swissEbill.violation.biller_uid_missing' => ['UID dell’azienda mancante.', 'Company UID missing.', 'UID des Unternehmens fehlt.', 'IDE de l’entreprise manquant.'],
            'swissEbill.violation.biller_uid_invalid' => ['L’UID dell’azienda non è valido.', 'The company UID is not valid.', 'Die UID des Unternehmens ist ungültig.', 'L’IDE de l’entreprise n’est pas valide.'],
            'swissEbill.violation.biller_iban_missing' => ['IBAN / QR-IBAN dell’azienda mancante.', 'Company IBAN / QR-IBAN missing.', 'IBAN / QR-IBAN des Unternehmens fehlt.', 'IBAN / QR-IBAN de l’entreprise manquant.'],
            'swissEbill.violation.qr_bill_invalid' => ['I dati della QR-fattura non sono validi (IBAN, riferimento o indirizzo).', 'The QR-bill data is not valid (IBAN, reference or address).', 'Die QR-Rechnungsdaten sind ungültig (IBAN, Referenz oder Adresse).', 'Les données de la facture QR ne sont pas valides (IBAN, référence ou adresse).'],
            'swissEbill.violation.recipient_missing' => ['Cliente della fattura mancante.', 'Invoice customer missing.', 'Kunde der Rechnung fehlt.', 'Client de la facture manquant.'],
            'swissEbill.violation.recipient_ebill_id_missing' => ['Il cliente non ha un ID partecipante eBill: verifica l’iscrizione.', 'The customer has no eBill participant ID: verify the enrollment.', 'Der Kunde hat keine eBill-Teilnehmer-ID: Anmeldung prüfen.', 'Le client n’a pas d’ID de participant eBill : vérifiez l’inscription.'],
            'swissEbill.violation.recipient_not_enrolled' => ['Il cliente non è iscritto a eBill.', 'The customer is not enrolled in eBill.', 'Der Kunde ist nicht bei eBill angemeldet.', 'Le client n’est pas inscrit à eBill.'],
            'swissEbill.violation.recipient_uid_missing' => ['Un cliente B2B richiede un UID valido.', 'A B2B customer needs a valid UID.', 'Ein B2B-Kunde braucht eine gültige UID.', 'Un client B2B exige un IDE valide.'],
            'swissEbill.violation.recipient_name_missing' => ['Nome del cliente mancante.', 'Customer name missing.', 'Name des Kunden fehlt.', 'Nom du client manquant.'],
            'swissEbill.violation.recipient_address_missing' => ['Indirizzo del cliente incompleto (CAP e località).', 'Customer address incomplete (postal code and city).', 'Adresse des Kunden unvollständig (PLZ und Ort).', 'Adresse du client incomplète (code postal et localité).'],
            'swissEbill.violation.payload_too_large' => ['Il payload supera il limite di dimensione SIX.', 'The payload exceeds the SIX size limit.', 'Der Payload überschreitet das SIX-Größenlimit.', 'La charge dépasse la limite de taille SIX.'],
            'swissEbill.violation.xml_malformed' => ['Il payload XML non è ben formato.', 'The XML payload is not well-formed.', 'Der XML-Payload ist nicht wohlgeformt.', 'La charge XML n’est pas bien formée.'],
            'swissEbill.violation.xsd_invalid' => ['Il payload XML non rispetta lo schema.', 'The XML payload does not match the schema.', 'Der XML-Payload entspricht nicht dem Schema.', 'La charge XML ne respecte pas le schéma.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'swissEbill.error.ebill_disabled' => ['L’eBill svizzero non è attivo per questa azienda.', 'Swiss eBill is not enabled for this company.', 'eBill ist für dieses Unternehmen nicht aktiviert.', 'eBill n’est pas activé pour cette entreprise.'],
            'swissEbill.error.not_configured' => ['L’eBill svizzero non è configurato.', 'Swiss eBill is not configured.', 'eBill ist nicht konfiguriert.', 'eBill n’est pas configuré.'],
            'swissEbill.error.already_transmitted' => ['Questa fattura ha già una trasmissione eBill attiva.', 'This invoice already has an active eBill transmission.', 'Diese Rechnung hat bereits eine aktive eBill-Übermittlung.', 'Cette facture a déjà une transmission eBill active.'],
            'swissEbill.error.cannot_revise' => ['Solo una trasmissione rifiutata, fallita o annullata può essere rivista.', 'Only a rejected, failed or cancelled transmission can be revised.', 'Nur eine abgelehnte, fehlgeschlagene oder stornierte Übermittlung kann überarbeitet werden.', 'Seule une transmission refusée, échouée ou annulée peut être révisée.'],
            'swissEbill.error.cannot_retry' => ['Solo un errore tecnico può essere ritentato.', 'Only a technical failure can be retried.', 'Nur ein technischer Fehler kann wiederholt werden.', 'Seule une erreur technique peut être relancée.'],
            'swissEbill.error.cannot_cancel' => ['Questo eBill non può più essere richiamato.', 'This eBill can no longer be recalled.', 'Diese eBill kann nicht mehr zurückgerufen werden.', 'Cette eBill ne peut plus être rappelée.'],
            'swissEbill.error.gateway_error' => ['Il gateway eBill ha rifiutato la richiesta.', 'The eBill gateway refused the request.', 'Das eBill-Gateway hat die Anfrage abgelehnt.', 'La passerelle eBill a refusé la demande.'],
            'swissEbill.error.gateway_unavailable' => ['Il gateway eBill non è raggiungibile: riprova più tardi.', 'The eBill gateway cannot be reached: try again later.', 'Das eBill-Gateway ist nicht erreichbar: später erneut versuchen.', 'La passerelle eBill est injoignable : réessayez plus tard.'],
            'swissEbill.error.payload_invalid' => ['L’eBill non può essere costruito: mancano dati obbligatori.', 'The eBill cannot be built: mandatory data is missing.', 'Die eBill kann nicht erstellt werden: Pflichtangaben fehlen.', 'L’eBill ne peut pas être construite : des données obligatoires manquent.'],
            'swissEbill.error.invalid_transition' => ['Il passaggio di stato non è ammesso.', 'This status change is not allowed.', 'Dieser Statuswechsel ist nicht zulässig.', 'Ce changement de statut n’est pas permis.'],
            'swissEbill.error.participant_not_found' => ['Partecipante non presente nella directory eBill.', 'Participant not found in the eBill directory.', 'Teilnehmer nicht im eBill-Verzeichnis gefunden.', 'Participant introuvable dans l’annuaire eBill.'],
            'swissEbill.error.invalid_lookup_value' => ['Identificativo non valido per questo tipo di ricerca.', 'Identifier not valid for this lookup type.', 'Kennung für diesen Suchtyp ungültig.', 'Identifiant non valide pour ce type de recherche.'],
            'swissEbill.error.payload_unavailable' => ['Nessun payload archiviato.', 'No payload stored.', 'Kein Payload gespeichert.', 'Aucune charge conservée.'],
            'swissEbill.error.sandbox_not_allowed' => ['Il simulatore sandbox non è disponibile in produzione.', 'The sandbox simulator is not available in production.', 'Der Sandbox-Simulator ist in der Produktion nicht verfügbar.', 'Le simulateur sandbox n’est pas disponible en production.'],
            'swissEbill.error.unknown' => ['Operazione eBill non riuscita.', 'The eBill operation failed.', 'Die eBill-Operation ist fehlgeschlagen.', 'L’opération eBill a échoué.'],
            'swissEbill.error.invalid_biller_id' => ['Il Biller ID non è valido (17 cifre con cifra di controllo).', 'The Biller ID is not valid (17 digits with check digit).', 'Die Biller-ID ist ungültig (17 Ziffern mit Prüfziffer).', 'Le Biller ID n’est pas valide (17 chiffres avec chiffre de contrôle).'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'swissEbill.field.ebillEnabled' => ['eBill attivo', 'eBill enabled', 'eBill aktiv', 'eBill activé'],
            'swissEbill.field.billerId' => ['Biller ID SIX Paynet (17 cifre)', 'SIX Paynet Biller ID (17 digits)', 'SIX-Paynet-Biller-ID (17 Ziffern)', 'Biller ID SIX Paynet (17 chiffres)'],
            'swissEbill.field.provider' => ['Provider', 'Provider', 'Anbieter', 'Fournisseur'],
            'swissEbill.field.protocol' => ['Protocollo del canale', 'Channel protocol', 'Kanalprotokoll', 'Protocole du canal'],
            'swissEbill.field.apiBaseUrl' => ['URL base API', 'API base URL', 'API-Basis-URL', 'URL de base de l’API'],
            'swissEbill.field.clientId' => ['Client ID', 'Client ID', 'Client-ID', 'Client ID'],
            'swissEbill.field.clientSecret' => ['Client secret', 'Client secret', 'Client-Secret', 'Client secret'],
            'swissEbill.field.signingCertificate' => ['Certificato di firma (PEM)', 'Signing certificate (PEM)', 'Signaturzertifikat (PEM)', 'Certificat de signature (PEM)'],
            'swissEbill.field.privateKey' => ['Chiave privata (PEM)', 'Private key (PEM)', 'Privater Schlüssel (PEM)', 'Clé privée (PEM)'],
            'swissEbill.field.webhookUrl' => ['URL del webhook da registrare presso il provider', 'Webhook URL to register with the provider', 'Webhook-URL zur Registrierung beim Anbieter', 'URL du webhook à enregistrer auprès du fournisseur'],
            'swissEbill.field.webhookSecret' => ['Segreto del webhook', 'Webhook secret', 'Webhook-Secret', 'Secret du webhook'],
            'swissEbill.field.webhookAuthMode' => ['Autenticazione del webhook', 'Webhook authentication', 'Webhook-Authentifizierung', 'Authentification du webhook'],
            'swissEbill.field.mtlsFingerprint' => ['Impronta del certificato client (mTLS)', 'Client certificate fingerprint (mTLS)', 'Fingerabdruck des Client-Zertifikats (mTLS)', 'Empreinte du certificat client (mTLS)'],
            'swissEbill.field.autoSend' => ['Invia automaticamente all’emissione della fattura', 'Send automatically when the invoice is issued', 'Bei Rechnungsausstellung automatisch senden', 'Envoyer automatiquement à l’émission de la facture'],
            'swissEbill.field.attachPdf' => ['Allega il PDF di cortesia con QR-fattura', 'Attach the courtesy PDF with QR-bill', 'Kulanz-PDF mit QR-Rechnung beilegen', 'Joindre le PDF de courtoisie avec facture QR'],
            'swissEbill.field.ebinterfaceVersion' => ['Formato del payload', 'Payload format', 'Payload-Format', 'Format de la charge'],
            'swissEbill.field.lastTested' => ['Ultimo test', 'Last test', 'Letzter Test', 'Dernier test'],
            'swissEbill.field.latency' => ['Latenza', 'Latency', 'Latenz', 'Latence'],
            'swissEbill.field.secretStored' => ['Segreto memorizzato (lascia vuoto per mantenerlo)', 'Secret stored (leave empty to keep it)', 'Secret gespeichert (leer lassen, um es zu behalten)', 'Secret enregistré (laisser vide pour le conserver)'],
            'swissEbill.field.lookupValue' => ['Valore da cercare', 'Value to look up', 'Zu suchender Wert', 'Valeur à rechercher'],
            'swissEbill.field.recipientId' => ['ID destinatario eBill', 'eBill recipient ID', 'eBill-Empfänger-ID', 'ID de destinataire eBill'],
            'swissEbill.field.verifiedAt' => ['Verificato il', 'Verified on', 'Geprüft am', 'Vérifié le'],
            'swissEbill.field.optInPreferred' => ['Preferisce la ricezione elettronica', 'Prefers electronic delivery', 'Bevorzugt elektronische Zustellung', 'Préfère la réception électronique'],
            'swissEbill.field.channel' => ['Canale', 'Channel', 'Kanal', 'Canal'],
            'swissEbill.field.participantName' => ['Partecipante', 'Participant', 'Teilnehmer', 'Participant'],
            'swissEbill.column.invoice' => ['Fattura', 'Invoice', 'Rechnung', 'Facture'],
            'swissEbill.column.customer' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'swissEbill.column.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'swissEbill.column.channel' => ['Canale', 'Channel', 'Kanal', 'Canal'],
            'swissEbill.column.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'swissEbill.column.transaction' => ['ID transazione SIX', 'SIX transaction ID', 'SIX-Transaktions-ID', 'ID de transaction SIX'],
            'swissEbill.column.submitted' => ['Trasmessa il', 'Transmitted on', 'Übermittelt am', 'Transmise le'],
            'swissEbill.column.scheduledPayment' => ['Pagamento programmato', 'Scheduled payment', 'Geplante Zahlung', 'Paiement planifié'],
            'swissEbill.column.attempts' => ['Tentativi', 'Attempts', 'Versuche', 'Tentatives'],
            'swissEbill.filter.status' => ['Stato eBill', 'eBill status', 'eBill-Status', 'Statut eBill'],
            'swissEbill.filter.dateRange' => ['Periodo', 'Date range', 'Zeitraum', 'Période'],
            'swissEbill.filter.customer' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'swissEbill.filter.channel' => ['Canale B2B / B2C', 'B2B / B2C channel', 'B2B-/B2C-Kanal', 'Canal B2B / B2C'],
            'swissEbill.filter.search' => ['Cerca fattura o transazione', 'Search invoice or transaction', 'Rechnung oder Transaktion suchen', 'Rechercher facture ou transaction'],
            'swissEbill.timeline.gatewayTransaction' => ['Transazione gateway', 'Gateway transaction', 'Gateway-Transaktion', 'Transaction passerelle'],
            'swissEbill.timeline.scheduledPayment' => ['Data di pagamento programmata', 'Scheduled payment date', 'Geplantes Zahlungsdatum', 'Date de paiement planifiée'],
            'swissEbill.timeline.settlementReference' => ['Riferimento camt.054', 'camt.054 reference', 'camt.054-Referenz', 'Référence camt.054'],
            'swissEbill.timeline.valueDate' => ['Data valuta', 'Value date', 'Valutadatum', 'Date de valeur'],
            'swissEbill.timeline.settledAmount' => ['Importo accreditato', 'Credited amount', 'Gutgeschriebener Betrag', 'Montant crédité'],
            'swissEbill.timeline.discrepancy' => ['Differenza rispetto all’eBill', 'Difference from the eBill', 'Differenz zur eBill', 'Écart par rapport à l’eBill'],
            'swissEbill.timeline.rejectionCode' => ['Codice di rifiuto della banca', 'Bank rejection code', 'Ablehnungscode der Bank', 'Code de refus de la banque'],
            'swissEbill.timeline.rejectionReason' => ['Motivo indicato dal cliente', 'Reason given by the customer', 'Vom Kunden angegebener Grund', 'Motif indiqué par le client'],
            'swissEbill.timeline.errorCode' => ['Codice errore', 'Error code', 'Fehlercode', 'Code d’erreur'],
            'swissEbill.timeline.nextRetry' => ['Prossimo tentativo', 'Next retry', 'Nächster Versuch', 'Prochaine tentative'],
            'swissEbill.timeline.xmlChecksum' => ['SHA-256 del payload XML', 'XML payload SHA-256', 'SHA-256 des XML-Payloads', 'SHA-256 de la charge XML'],
            'swissEbill.timeline.pdfChecksum' => ['SHA-256 del PDF di cortesia', 'Courtesy PDF SHA-256', 'SHA-256 des Kulanz-PDF', 'SHA-256 du PDF de courtoisie'],
            'swissEbill.timeline.pdfSize' => ['Dimensione del PDF', 'PDF size', 'Größe des PDF', 'Taille du PDF'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'swissEbill.action.verify' => ['Verifica iscrizione eBill', 'Verify eBill Enrollment', 'eBill-Anmeldung prüfen', 'Vérifier l’inscription eBill'],
            'swissEbill.action.checkDirectory' => ['Verifica nella directory SIX eBill', 'Check SIX eBill Directory', 'SIX-eBill-Verzeichnis prüfen', 'Consulter l’annuaire SIX eBill'],
            'swissEbill.action.send' => ['Invia via eBill', 'Send via eBill', 'Per eBill senden', 'Envoyer par eBill'],
            'swissEbill.action.resend' => ['Reinvia eBill', 'Resend eBill', 'eBill erneut senden', 'Renvoyer l’eBill'],
            'swissEbill.action.reviseResend' => ['Rivedi e reinvia eBill', 'Revise & Resend eBill', 'eBill überarbeiten und erneut senden', 'Réviser et renvoyer l’eBill'],
            'swissEbill.action.downloadXml' => ['Scarica XML SIX', 'Download SIX XML', 'SIX-XML herunterladen', 'Télécharger le XML SIX'],
            'swissEbill.action.viewLog' => ['Vedi registro di trasmissione', 'View Transmission Log', 'Übermittlungsprotokoll anzeigen', 'Voir le journal de transmission'],
            'swissEbill.action.testConnection' => ['Testa la connessione SIX', 'Test SIX Connection', 'SIX-Verbindung testen', 'Tester la connexion SIX'],
            'swissEbill.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'swissEbill.action.copy' => ['Copia', 'Copy', 'Kopieren', 'Copier'],
            'swissEbill.action.rotateSecret' => ['Genera nuovo segreto', 'Generate new secret', 'Neues Secret erzeugen', 'Générer un nouveau secret'],
            'swissEbill.action.cancel' => ['Richiama eBill', 'Recall eBill', 'eBill zurückrufen', 'Rappeler l’eBill'],
            'swissEbill.action.retry' => ['Riprova la trasmissione', 'Retry transmission', 'Übermittlung wiederholen', 'Relancer la transmission'],
            'swissEbill.action.link' => ['Collega al cliente', 'Link to customer', 'Mit Kunde verknüpfen', 'Lier au client'],
            'swissEbill.action.lookup' => ['Cerca', 'Look up', 'Suchen', 'Rechercher'],
            'swissEbill.action.refresh' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'swissEbill.action.bulkVerify' => ['Verifica idoneità eBill', 'Verify eBill Eligibility', 'eBill-Eignung prüfen', 'Vérifier l’éligibilité eBill'],
            'swissEbill.action.openInvoice' => ['Apri fattura', 'Open invoice', 'Rechnung öffnen', 'Ouvrir la facture'],
            'swissEbill.action.openSettings' => ['Apri configurazione eBill', 'Open eBill configuration', 'eBill-Konfiguration öffnen', 'Ouvrir la configuration eBill'],
            'swissEbill.action.forceRefresh' => ['Ignora la cache e interroga il gateway', 'Ignore the cache and ask the gateway', 'Cache ignorieren und Gateway abfragen', 'Ignorer le cache et interroger la passerelle'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'swissEbill.section.connection' => ['Connessione al gateway', 'Gateway connection', 'Gateway-Verbindung', 'Connexion à la passerelle'],
            'swissEbill.section.credentials' => ['Credenziali e certificati', 'Credentials and certificates', 'Zugangsdaten und Zertifikate', 'Identifiants et certificats'],
            'swissEbill.section.webhook' => ['Webhook di notifica', 'Notification webhook', 'Benachrichtigungs-Webhook', 'Webhook de notification'],
            'swissEbill.section.delivery' => ['Opzioni di invio', 'Delivery options', 'Versandoptionen', 'Options d’envoi'],
            'swissEbill.section.customerCard' => ['eBill e recapito elettronico', 'eBill & Electronic Delivery', 'eBill und elektronische Zustellung', 'eBill et remise électronique'],
            'swissEbill.section.lifecycle' => ['Ciclo di vita eBill', 'eBill lifecycle', 'eBill-Lebenszyklus', 'Cycle de vie eBill'],
            'swissEbill.section.preflight' => ['Controlli prima dell’invio', 'Pre-flight checks', 'Prüfungen vor dem Versand', 'Contrôles avant l’envoi'],
            'swissEbill.section.matchedParticipant' => ['Partecipante trovato', 'Matched participant', 'Gefundener Teilnehmer', 'Participant trouvé'],
            'swissEbill.section.transmissionLog' => ['Registro delle trasmissioni', 'Transmission log', 'Übermittlungsprotokoll', 'Journal des transmissions'],
            'swissEbill.section.audit' => ['Audit del payload', 'Payload audit', 'Payload-Audit', 'Audit de la charge'],
            'swissEbill.empty.log' => ['Nessuna trasmissione eBill con questi filtri.', 'No eBill transmission matches these filters.', 'Keine eBill-Übermittlung mit diesen Filtern.', 'Aucune transmission eBill avec ces filtres.'],
            'swissEbill.empty.timeline' => ['Questa fattura non è ancora stata inviata via eBill.', 'This invoice has not been sent via eBill yet.', 'Diese Rechnung wurde noch nicht per eBill gesendet.', 'Cette facture n’a pas encore été envoyée par eBill.'],
            'swissEbill.empty.directory' => ['Verifica il cliente nella directory per vedere se riceve eBill.', 'Check the customer in the directory to see whether they receive eBill.', 'Prüfen Sie den Kunden im Verzeichnis, ob er eBill empfängt.', 'Consultez l’annuaire pour savoir si le client reçoit eBill.'],
            'swissEbill.empty.notConfigured' => ['L’eBill svizzero non è ancora attivo: configuralo per inviare le fatture direttamente alle banche dei clienti.', 'Swiss eBill is not active yet: configure it to send invoices straight to the banks of your customers.', 'Schweizer eBill ist noch nicht aktiv: Konfigurieren Sie es, um Rechnungen direkt an die Banken Ihrer Kunden zu senden.', 'eBill suisse n’est pas encore actif : configurez-le pour envoyer les factures directement aux banques de vos clients.'],
            'swissEbill.hint.webhook' => ['Registra questo indirizzo nel portale SIX Paynet / PostFinance: le notifiche di consegna, approvazione e rifiuto arrivano qui. Il segreto viene mostrato una sola volta.', 'Register this address in the SIX Paynet / PostFinance portal: delivery, approval and rejection notifications arrive here. The secret is shown only once.', 'Registrieren Sie diese Adresse im Portal von SIX Paynet / PostFinance: Zustell-, Freigabe- und Ablehnungsmeldungen kommen hierher. Das Secret wird nur einmal angezeigt.', 'Enregistrez cette adresse dans le portail SIX Paynet / PostFinance : les notifications de remise, d’approbation et de refus arrivent ici. Le secret n’est affiché qu’une fois.'],
            'swissEbill.hint.sandbox' => ['Il simulatore sandbox non contatta alcuna rete: serve per prove e demo, non è disponibile in produzione.', 'The sandbox simulator contacts no network: it is for trials and demos and is not available in production.', 'Der Sandbox-Simulator kontaktiert kein Netz: für Tests und Demos, in der Produktion nicht verfügbar.', 'Le simulateur sandbox ne contacte aucun réseau : il sert aux essais et démos et n’est pas disponible en production.'],
            'swissEbill.hint.lookup' => ['La ricerca interroga la directory dei partecipanti SIX Paynet / PostFinance per UID, email, IBAN o ID partecipante.', 'The lookup asks the SIX Paynet / PostFinance participant directory by UID, email, IBAN or participant ID.', 'Die Suche fragt das Teilnehmerverzeichnis von SIX Paynet / PostFinance nach UID, E-Mail, IBAN oder Teilnehmer-ID ab.', 'La recherche interroge l’annuaire des participants SIX Paynet / PostFinance par IDE, e-mail, IBAN ou ID de participant.'],
            'swissEbill.hint.discrepancy' => ['L’importo accreditato differisce da quello dell’eBill: controlla commissioni, note di credito o pagamenti parziali.', 'The credited amount differs from the eBill amount: check bank charges, credit notes or partial payments.', 'Der gutgeschriebene Betrag weicht von der eBill ab: Bankspesen, Gutschriften oder Teilzahlungen prüfen.', 'Le montant crédité diffère de celui de l’eBill : vérifiez frais bancaires, notes de crédit ou paiements partiels.'],
            'swissEbill.hint.noPayload' => ['Il payload XML e il checksum del PDF sono conservati per l’audit.', 'The XML payload and the PDF checksum are kept for audit.', 'XML-Payload und PDF-Prüfsumme werden für das Audit aufbewahrt.', 'La charge XML et la somme de contrôle du PDF sont conservées pour l’audit.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'swissEbill.toast.settingsSaved' => ['Impostazioni eBill salvate.', 'eBill settings saved.', 'eBill-Einstellungen gespeichert.', 'Paramètres eBill enregistrés.'],
            'swissEbill.toast.connectionOk' => ['Connessione al gateway riuscita.', 'Gateway connection succeeded.', 'Verbindung zum Gateway erfolgreich.', 'Connexion à la passerelle réussie.'],
            'swissEbill.toast.connectionFailed' => ['Connessione al gateway non riuscita.', 'Gateway connection failed.', 'Verbindung zum Gateway fehlgeschlagen.', 'Connexion à la passerelle échouée.'],
            'swissEbill.toast.copied' => ['Copiato negli appunti.', 'Copied to the clipboard.', 'In die Zwischenablage kopiert.', 'Copié dans le presse-papiers.'],
            'swissEbill.toast.secretRotated' => ['Nuovo segreto emesso: copialo ora, non verrà mostrato di nuovo.', 'New secret issued: copy it now, it will not be shown again.', 'Neues Secret erstellt: jetzt kopieren, es wird nicht erneut angezeigt.', 'Nouveau secret émis : copiez-le maintenant, il ne sera plus affiché.'],
            'swissEbill.toast.participantFound' => ['Partecipante trovato nella directory.', 'Participant found in the directory.', 'Teilnehmer im Verzeichnis gefunden.', 'Participant trouvé dans l’annuaire.'],
            'swissEbill.toast.participantNotFound' => ['Nessun partecipante trovato.', 'No participant found.', 'Kein Teilnehmer gefunden.', 'Aucun participant trouvé.'],
            'swissEbill.toast.customerSynced' => ['Stato eBill del cliente aggiornato.', 'Customer eBill status updated.', 'eBill-Status des Kunden aktualisiert.', 'Statut eBill du client mis à jour.'],
            'swissEbill.toast.customerLinked' => ['Cliente collegato al partecipante eBill.', 'Customer linked to the eBill participant.', 'Kunde mit eBill-Teilnehmer verknüpft.', 'Client lié au participant eBill.'],
            'swissEbill.toast.bulkQueued' => ['Verifica dei clienti avviata in background.', 'Customer verification started in the background.', 'Kundenprüfung im Hintergrund gestartet.', 'Vérification des clients lancée en arrière-plan.'],
            'swissEbill.toast.transmissionQueued' => ['Fattura messa in coda per l’invio eBill.', 'Invoice queued for eBill delivery.', 'Rechnung für den eBill-Versand eingereiht.', 'Facture mise en file pour l’envoi eBill.'],
            'swissEbill.toast.transmissionCancelled' => ['eBill richiamato.', 'eBill recalled.', 'eBill zurückgerufen.', 'eBill rappelée.'],
            'swissEbill.confirm.send.title' => ['Inviare la fattura via eBill?', 'Send the invoice via eBill?', 'Rechnung per eBill senden?', 'Envoyer la facture par eBill ?'],
            'swissEbill.confirm.send.body' => ['La fattura sarà trasmessa direttamente alla banca del cliente tramite SIX Paynet / PostFinance.', 'The invoice will be transmitted straight to the bank of the customer through SIX Paynet / PostFinance.', 'Die Rechnung wird über SIX Paynet / PostFinance direkt an die Bank des Kunden übermittelt.', 'La facture sera transmise directement à la banque du client via SIX Paynet / PostFinance.'],
            'swissEbill.confirm.cancel.title' => ['Richiamare l’eBill?', 'Recall the eBill?', 'eBill zurückrufen?', 'Rappeler l’eBill ?'],
            'swissEbill.confirm.cancel.body' => ['Il cliente non potrà più approvare questa fattura nell’e-banking.', 'The customer will no longer be able to approve this invoice in the e-banking.', 'Der Kunde kann diese Rechnung im E-Banking nicht mehr freigeben.', 'Le client ne pourra plus approuver cette facture dans l’e-banking.'],
            'swissEbill.confirm.revise.title' => ['Rivedere e reinviare?', 'Revise and resend?', 'Überarbeiten und erneut senden?', 'Réviser et renvoyer ?'],
            'swissEbill.confirm.revise.body' => ['Il payload viene ricostruito dai dati attuali di fattura e cliente e inviato di nuovo.', 'The payload is rebuilt from the current invoice and customer data and sent again.', 'Der Payload wird aus den aktuellen Rechnungs- und Kundendaten neu erstellt und erneut gesendet.', 'La charge est reconstruite à partir des données actuelles de la facture et du client puis renvoyée.'],
            'swissEbill.confirm.rotate.title' => ['Generare un nuovo segreto?', 'Generate a new secret?', 'Neues Secret erzeugen?', 'Générer un nouveau secret ?'],
            'swissEbill.confirm.rotate.body' => ['Il segreto attuale smette di valere: aggiornalo nel portale del provider.', 'The current secret stops working: update it in the portal of the provider.', 'Das aktuelle Secret verliert seine Gültigkeit: im Portal des Anbieters aktualisieren.', 'Le secret actuel cesse de fonctionner : mettez-le à jour dans le portail du fournisseur.'],
            'swissEbill.confirm.bulk.title' => ['Verificare l’idoneità eBill?', 'Verify eBill eligibility?', 'eBill-Eignung prüfen?', 'Vérifier l’éligibilité eBill ?'],
            'swissEbill.confirm.bulk.body' => ['I clienti selezionati saranno verificati nella directory in background.', 'The selected customers are checked against the directory in the background.', 'Die ausgewählten Kunden werden im Hintergrund im Verzeichnis geprüft.', 'Les clients sélectionnés sont vérifiés dans l’annuaire en arrière-plan.'],
            'swissEbill.alert.rejectedTitle' => ['Il cliente ha rifiutato questa fattura', 'The customer rejected this invoice', 'Der Kunde hat diese Rechnung abgelehnt', 'Le client a refusé cette facture'],
            'swissEbill.alert.failedTitle' => ['Trasmissione non riuscita', 'Transmission failed', 'Übermittlung fehlgeschlagen', 'Transmission échouée'],
            'swissEbill.alert.discrepancyTitle' => ['Importo accreditato diverso dall’eBill', 'Credited amount differs from the eBill', 'Gutgeschriebener Betrag weicht von der eBill ab', 'Montant crédité différent de l’eBill'],
            'swissEbill.alert.connectionOk' => ['Gateway raggiungibile e credenziali accettate.', 'Gateway reachable and credentials accepted.', 'Gateway erreichbar und Zugangsdaten akzeptiert.', 'Passerelle joignable et identifiants acceptés.'],
            'swissEbill.alert.connectionFailed' => ['Il gateway non risponde o rifiuta le credenziali.', 'The gateway does not answer or refuses the credentials.', 'Das Gateway antwortet nicht oder lehnt die Zugangsdaten ab.', 'La passerelle ne répond pas ou refuse les identifiants.'],
            'swissEbill.notification.ebill_rejected' => ['eBill rifiutato dal cliente: fattura {{invoice}}', 'eBill rejected by the customer: invoice {{invoice}}', 'eBill vom Kunden abgelehnt: Rechnung {{invoice}}', 'eBill refusée par le client : facture {{invoice}}'],
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
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        $resolved = [];

        foreach (self::definitions() as $key => $tuple) {
            $resolved[$key] = $tuple[$index];
        }

        return $resolved;
    }
}
