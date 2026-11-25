<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the customer satisfaction module (Mod 245): the ISO 9001 § 9.1.2 dashboard, the closed-loop detractor recovery
 * queue, the survey templates with their trigger rules, the dispatch monitor and the public mobile landing page of a survey, plus the
 * codes the API reports (customerSatisfaction.error.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, NPS, CSAT, CES, SLA, CRM, ISO) stay untranslated.
 */
final class CustomerSatisfactionFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::errors(),
            self::fields(),
            self::actions(),
            self::sections(),
            self::messages(),
            self::publicPage(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.CUSTOMER_SATISFACTION' => ['Soddisfazione clienti', 'Customer satisfaction', 'Kundenzufriedenheit', 'Satisfaction client'],
            'NAV.CUSTOMER_SATISFACTION_DASHBOARD' => ['Cruscotto ISO 9001', 'ISO 9001 dashboard', 'ISO-9001-Dashboard', 'Tableau de bord ISO 9001'],
            'NAV.CUSTOMER_SATISFACTION_RECOVERY' => ['Recupero detrattori', 'Detractor recovery', 'Detraktoren-Rückgewinnung', 'Récupération des détracteurs'],
            'NAV.CUSTOMER_SATISFACTION_TEMPLATES' => ['Modelli e regole', 'Templates and rules', 'Vorlagen und Regeln', 'Modèles et règles'],
            'NAV.CUSTOMER_SATISFACTION_DISPATCHES' => ['Invii e risposte', 'Dispatches and answers', 'Versand und Antworten', 'Envois et réponses'],
            'PAGE_TITLE.CUSTOMER_SATISFACTION_DASHBOARD' => ['Soddisfazione clienti: NPS, CSAT, CES e riesame ISO 9001', 'Customer satisfaction: NPS, CSAT, CES and ISO 9001 review', 'Kundenzufriedenheit: NPS, CSAT, CES und ISO-9001-Bewertung', 'Satisfaction client : NPS, CSAT, CES et revue ISO 9001'],
            'PAGE_TITLE.CUSTOMER_SATISFACTION_RECOVERY' => ['Coda di recupero dei detrattori (closed-loop)', 'Detractor recovery queue (closed loop)', 'Warteschlange der Detraktoren-Rückgewinnung (Closed Loop)', 'File de récupération des détracteurs (closed loop)'],
            'PAGE_TITLE.CUSTOMER_SATISFACTION_TEMPLATES' => ['Modelli di sondaggio e regole di invio', 'Survey templates and trigger rules', 'Umfragevorlagen und Auslöseregeln', 'Modèles d’enquête et règles de déclenchement'],
            'PAGE_TITLE.CUSTOMER_SATISFACTION_DISPATCHES' => ['Monitor degli invii e registro delle risposte', 'Dispatch monitor and answer log', 'Versandmonitor und Antwortprotokoll', 'Suivi des envois et journal des réponses'],
            'PAGE_TITLE.CUSTOMER_SATISFACTION_SURVEY' => ['Il suo parere conta', 'Your opinion matters', 'Ihre Meinung zählt', 'Votre avis compte'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'customerSatisfaction.surveyType.nps' => ['NPS', 'NPS', 'NPS', 'NPS'],
            'customerSatisfaction.surveyType.csat' => ['CSAT', 'CSAT', 'CSAT', 'CSAT'],
            'customerSatisfaction.surveyType.ces' => ['CES', 'CES', 'CES', 'CES'],

            'customerSatisfaction.trigger.ticket_closed' => ['Ticket chiuso', 'Ticket closed', 'Ticket geschlossen', 'Ticket clôturé'],
            'customerSatisfaction.trigger.order_delivered' => ['Ordine consegnato', 'Order delivered', 'Bestellung geliefert', 'Commande livrée'],
            'customerSatisfaction.trigger.field_intervention_signed' => ['Intervento in campo firmato', 'Field intervention signed', 'Einsatz vor Ort unterzeichnet', 'Intervention sur site signée'],
            'customerSatisfaction.trigger.onboarding_completed' => ['Onboarding completato', 'Onboarding completed', 'Onboarding abgeschlossen', 'Onboarding terminé'],
            'customerSatisfaction.trigger.semi_annual_active_client' => ['Cliente attivo (semestrale)', 'Active client (semi-annual)', 'Aktiver Kunde (halbjährlich)', 'Client actif (semestriel)'],

            'customerSatisfaction.channel.email' => ['Email', 'Email', 'E-Mail', 'E-mail'],
            'customerSatisfaction.channel.sms' => ['SMS', 'SMS', 'SMS', 'SMS'],
            'customerSatisfaction.channel.whatsapp' => ['WhatsApp', 'WhatsApp', 'WhatsApp', 'WhatsApp'],

            'customerSatisfaction.dispatchStatus.scheduled' => ['Programmato', 'Scheduled', 'Geplant', 'Planifié'],
            'customerSatisfaction.dispatchStatus.sent' => ['Inviato', 'Sent', 'Gesendet', 'Envoyé'],
            'customerSatisfaction.dispatchStatus.delivered' => ['Consegnato', 'Delivered', 'Zugestellt', 'Remis'],
            'customerSatisfaction.dispatchStatus.responded' => ['Risposto', 'Answered', 'Beantwortet', 'Répondu'],
            'customerSatisfaction.dispatchStatus.throttled' => ['Limitato', 'Throttled', 'Gedrosselt', 'Limité'],
            'customerSatisfaction.dispatchStatus.suppressed' => ['Soppresso', 'Suppressed', 'Unterdrückt', 'Supprimé'],
            'customerSatisfaction.dispatchStatus.failed' => ['Fallito', 'Failed', 'Fehlgeschlagen', 'Échoué'],
            'customerSatisfaction.dispatchStatus.expired' => ['Scaduto', 'Expired', 'Abgelaufen', 'Expiré'],

            'customerSatisfaction.throttleReason.cooldown_active' => ['Periodo di pausa attivo', 'Cooldown active', 'Sperrfrist aktiv', 'Période de pause active'],
            'customerSatisfaction.throttleReason.opt_out' => ['Il cliente ha rifiutato i sondaggi', 'The customer opted out', 'Der Kunde hat Umfragen abgelehnt', 'Le client a refusé les enquêtes'],
            'customerSatisfaction.throttleReason.missing_contact' => ['Recapito mancante', 'Missing contact details', 'Kontaktdaten fehlen', 'Coordonnées manquantes'],
            'customerSatisfaction.throttleReason.invalid_event' => ['Evento non valido', 'Invalid event', 'Ungültiges Ereignis', 'Événement non valide'],

            'customerSatisfaction.band.promoter' => ['Promotori', 'Promoters', 'Promotoren', 'Promoteurs'],
            'customerSatisfaction.band.passive' => ['Passivi', 'Passives', 'Passive', 'Passifs'],
            'customerSatisfaction.band.detractor' => ['Detrattori', 'Detractors', 'Detraktoren', 'Détracteurs'],
            'customerSatisfaction.band.satisfied' => ['Soddisfatti', 'Satisfied', 'Zufrieden', 'Satisfaits'],
            'customerSatisfaction.band.neutral' => ['Neutri', 'Neutral', 'Neutral', 'Neutres'],
            'customerSatisfaction.band.dissatisfied' => ['Insoddisfatti', 'Dissatisfied', 'Unzufrieden', 'Insatisfaits'],

            'customerSatisfaction.recoveryStatus.open' => ['Aperto', 'Open', 'Offen', 'Ouvert'],
            'customerSatisfaction.recoveryStatus.in_progress' => ['In lavorazione', 'In progress', 'In Bearbeitung', 'En cours'],
            'customerSatisfaction.recoveryStatus.pending_customer' => ['In attesa del cliente', 'Waiting for the customer', 'Wartet auf den Kunden', 'En attente du client'],
            'customerSatisfaction.recoveryStatus.resolved' => ['Risolto', 'Resolved', 'Gelöst', 'Résolu'],
            'customerSatisfaction.recoveryStatus.closed' => ['Chiuso', 'Closed', 'Geschlossen', 'Clôturé'],

            'customerSatisfaction.priority.high' => ['Alta', 'High', 'Hoch', 'Haute'],
            'customerSatisfaction.priority.urgent' => ['Urgente', 'Urgent', 'Dringend', 'Urgente'],

            'customerSatisfaction.churn.low' => ['Rischio basso', 'Low risk', 'Geringes Risiko', 'Risque faible'],
            'customerSatisfaction.churn.medium' => ['Rischio medio', 'Medium risk', 'Mittleres Risiko', 'Risque moyen'],
            'customerSatisfaction.churn.high' => ['Rischio alto', 'High risk', 'Hohes Risiko', 'Risque élevé'],
            'customerSatisfaction.churn.critical' => ['Rischio critico', 'Critical risk', 'Kritisches Risiko', 'Risque critique'],

            'customerSatisfaction.sentiment.positive' => ['Positivo', 'Positive', 'Positiv', 'Positif'],
            'customerSatisfaction.sentiment.neutral' => ['Neutro', 'Neutral', 'Neutral', 'Neutre'],
            'customerSatisfaction.sentiment.negative' => ['Negativo', 'Negative', 'Negativ', 'Négatif'],

            'customerSatisfaction.topic.delivery' => ['Consegna', 'Delivery', 'Lieferung', 'Livraison'],
            'customerSatisfaction.topic.price' => ['Prezzo', 'Price', 'Preis', 'Prix'],
            'customerSatisfaction.topic.support' => ['Assistenza', 'Support', 'Support', 'Assistance'],
            'customerSatisfaction.topic.quality' => ['Qualità', 'Quality', 'Qualität', 'Qualité'],
            'customerSatisfaction.topic.speed' => ['Rapidità', 'Speed', 'Geschwindigkeit', 'Rapidité'],
            'customerSatisfaction.topic.communication' => ['Comunicazione', 'Communication', 'Kommunikation', 'Communication'],
            'customerSatisfaction.topic.staff' => ['Personale', 'Staff', 'Personal', 'Personnel'],

            'customerSatisfaction.granularity.day' => ['Giorno', 'Day', 'Tag', 'Jour'],
            'customerSatisfaction.granularity.week' => ['Settimana', 'Week', 'Woche', 'Semaine'],
            'customerSatisfaction.granularity.month' => ['Mese', 'Month', 'Monat', 'Mois'],
            'customerSatisfaction.granularity.quarter' => ['Trimestre', 'Quarter', 'Quartal', 'Trimestre'],

            'customerSatisfaction.dimension.overall' => ['Complessivo', 'Overall', 'Gesamt', 'Global'],
            'customerSatisfaction.dimension.department' => ['Reparto', 'Department', 'Abteilung', 'Département'],
            'customerSatisfaction.dimension.technician' => ['Tecnico', 'Technician', 'Techniker', 'Technicien'],
            'customerSatisfaction.dimension.product_line' => ['Linea di prodotto', 'Product line', 'Produktlinie', 'Gamme de produits'],
            'customerSatisfaction.dimension.branch' => ['Filiale', 'Branch', 'Filiale', 'Succursale'],

            'customerSatisfaction.rootCause.product_defect' => ['Difetto del prodotto', 'Product defect', 'Produktmangel', 'Défaut du produit'],
            'customerSatisfaction.rootCause.delivery_delay' => ['Ritardo nella consegna', 'Delivery delay', 'Lieferverzögerung', 'Retard de livraison'],
            'customerSatisfaction.rootCause.service_quality' => ['Qualità del servizio', 'Service quality', 'Servicequalität', 'Qualité du service'],
            'customerSatisfaction.rootCause.billing_error' => ['Errore di fatturazione', 'Billing error', 'Fakturierungsfehler', 'Erreur de facturation'],
            'customerSatisfaction.rootCause.communication' => ['Comunicazione', 'Communication', 'Kommunikation', 'Communication'],
            'customerSatisfaction.rootCause.pricing' => ['Prezzi', 'Pricing', 'Preisgestaltung', 'Tarification'],
            'customerSatisfaction.rootCause.staff_behaviour' => ['Comportamento del personale', 'Staff behaviour', 'Verhalten des Personals', 'Comportement du personnel'],
            'customerSatisfaction.rootCause.process_complexity' => ['Complessità del processo', 'Process complexity', 'Prozesskomplexität', 'Complexité du processus'],
            'customerSatisfaction.rootCause.other' => ['Altro', 'Other', 'Andere', 'Autre'],

            'customerSatisfaction.submittedVia.one_click_link' => ['Link a un clic', 'One-click link', 'Ein-Klick-Link', 'Lien en un clic'],
            'customerSatisfaction.submittedVia.landing_page' => ['Pagina web', 'Landing page', 'Webseite', 'Page web'],
            'customerSatisfaction.submittedVia.whatsapp_reply' => ['Risposta WhatsApp', 'WhatsApp reply', 'WhatsApp-Antwort', 'Réponse WhatsApp'],
            'customerSatisfaction.submittedVia.portal' => ['Portale clienti', 'Customer portal', 'Kundenportal', 'Portail client'],

            'customerSatisfaction.followUp.low' => ['Domanda per punteggi bassi (detrattori)', 'Question for low scores (detractors)', 'Frage bei niedrigen Bewertungen (Detraktoren)', 'Question pour les notes basses (détracteurs)'],
            'customerSatisfaction.followUp.mid' => ['Domanda per punteggi intermedi (passivi)', 'Question for middle scores (passives)', 'Frage bei mittleren Bewertungen (Passive)', 'Question pour les notes moyennes (passifs)'],
            'customerSatisfaction.followUp.high' => ['Domanda per punteggi alti (promotori)', 'Question for high scores (promoters)', 'Frage bei hohen Bewertungen (Promotoren)', 'Question pour les notes élevées (promoteurs)'],

            'customerSatisfaction.export.json' => ['JSON', 'JSON', 'JSON', 'JSON'],
            'customerSatisfaction.export.csv' => ['CSV', 'CSV', 'CSV', 'CSV'],
            'customerSatisfaction.export.html' => ['Stampa', 'Print', 'Drucken', 'Imprimer'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'customerSatisfaction.error.generic' => ['Operazione non riuscita. Riprovi.', 'The operation failed. Please try again.', 'Der Vorgang ist fehlgeschlagen. Bitte erneut versuchen.', 'L’opération a échoué. Veuillez réessayer.'],
            'customerSatisfaction.error.survey_not_found' => ['Questo link del sondaggio non è valido.', 'This survey link is not valid.', 'Dieser Umfragelink ist ungültig.', 'Ce lien d’enquête n’est pas valide.'],
            'customerSatisfaction.error.survey_expired' => ['Questo link del sondaggio è scaduto.', 'This survey link has expired.', 'Dieser Umfragelink ist abgelaufen.', 'Ce lien d’enquête a expiré.'],
            'customerSatisfaction.error.survey_not_available' => ['Questo sondaggio non è disponibile.', 'This survey is not available.', 'Diese Umfrage ist nicht verfügbar.', 'Cette enquête n’est pas disponible.'],
            'customerSatisfaction.error.invalid_score' => ['Il punteggio deve essere compreso tra {min} e {max}.', 'The score must be between {min} and {max}.', 'Die Bewertung muss zwischen {min} und {max} liegen.', 'La note doit être comprise entre {min} et {max}.'],
            'customerSatisfaction.error.score_required' => ['Indichi prima un punteggio, poi aggiunga il commento.', 'Give a score first, then add your comment.', 'Geben Sie zuerst eine Bewertung ab und ergänzen Sie dann Ihren Kommentar.', 'Donnez d’abord une note, puis ajoutez votre commentaire.'],
            'customerSatisfaction.error.comment_required' => ['Scriva un commento o registri una nota vocale.', 'Write a comment or record a voice note.', 'Schreiben Sie einen Kommentar oder nehmen Sie eine Sprachnachricht auf.', 'Écrivez un commentaire ou enregistrez une note vocale.'],
            'customerSatisfaction.error.voice_not_allowed' => ['I commenti vocali non sono abilitati per questo sondaggio.', 'Voice comments are not enabled for this survey.', 'Sprachkommentare sind für diese Umfrage nicht aktiviert.', 'Les commentaires vocaux ne sont pas activés pour cette enquête.'],
            'customerSatisfaction.error.microphone_unavailable' => ['Il microfono non è disponibile. Carichi un file audio oppure scriva un commento.', 'The microphone is not available. Upload an audio file or write a comment.', 'Das Mikrofon ist nicht verfügbar. Laden Sie eine Audiodatei hoch oder schreiben Sie einen Kommentar.', 'Le microphone n’est pas disponible. Téléversez un fichier audio ou écrivez un commentaire.'],
            'customerSatisfaction.error.invalid_status_transition' => ['Un caso non può passare da {from} a {to}.', 'A case cannot move from {from} to {to}.', 'Ein Fall kann nicht von {from} nach {to} wechseln.', 'Un dossier ne peut pas passer de {from} à {to}.'],
            'customerSatisfaction.error.resolution_requires_root_cause' => ['Per risolvere o chiudere un caso servono la causa radice e il riepilogo della risoluzione.', 'Resolving or closing a case needs the root cause and the resolution summary.', 'Zum Lösen oder Schliessen eines Falls sind die Grundursache und die Lösungszusammenfassung nötig.', 'Résoudre ou clôturer un dossier exige la cause racine et le résumé de la résolution.'],
            'customerSatisfaction.error.unsupported_export_format' => ['Formato di esportazione non supportato.', 'Unsupported export format.', 'Nicht unterstütztes Exportformat.', 'Format d’exportation non pris en charge.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'customerSatisfaction.field.from' => ['Dal', 'From', 'Von', 'Du'],
            'customerSatisfaction.field.to' => ['Al', 'To', 'Bis', 'Au'],
            'customerSatisfaction.field.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'customerSatisfaction.field.surveyType' => ['Strumento', 'Instrument', 'Instrument', 'Instrument'],
            'customerSatisfaction.field.granularity' => ['Granularità', 'Granularity', 'Granularität', 'Granularité'],
            'customerSatisfaction.field.groupBy' => ['Raggruppa per', 'Group by', 'Gruppieren nach', 'Regrouper par'],
            'customerSatisfaction.field.dimension' => ['Dimensione', 'Dimension', 'Dimension', 'Dimension'],
            'customerSatisfaction.field.unassigned' => ['Non assegnato', 'Unassigned', 'Nicht zugewiesen', 'Non attribué'],
            'customerSatisfaction.field.reportTitle' => ['Titolo del report', 'Report title', 'Berichtstitel', 'Titre du rapport'],
            'customerSatisfaction.field.managementReviewNotes' => ['Note del Riesame della Direzione', 'Management review notes', 'Notizen der Managementbewertung', 'Notes de la revue de direction'],
            'customerSatisfaction.field.actions' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'customerSatisfaction.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'customerSatisfaction.field.priority' => ['Priorità', 'Priority', 'Priorität', 'Priorité'],
            'customerSatisfaction.field.openOnly' => ['Solo casi aperti', 'Open cases only', 'Nur offene Fälle', 'Dossiers ouverts uniquement'],
            'customerSatisfaction.field.customer' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'customerSatisfaction.field.score' => ['Punteggio', 'Score', 'Bewertung', 'Note'],
            'customerSatisfaction.field.sla' => ['SLA 24h', '24h SLA', 'SLA 24h', 'SLA 24h'],
            'customerSatisfaction.field.churnRisk' => ['Rischio di abbandono', 'Churn risk', 'Abwanderungsrisiko', 'Risque de départ'],
            'customerSatisfaction.field.owners' => ['Responsabili', 'Owners', 'Verantwortliche', 'Responsables'],
            'customerSatisfaction.field.accountManager' => ['Account manager', 'Account manager', 'Account Manager', 'Account manager'],
            'customerSatisfaction.field.supportLead' => ['Responsabile assistenza', 'Support lead', 'Support-Leiter', 'Responsable du support'],
            'customerSatisfaction.field.crmTask' => ['Attività CRM', 'CRM task', 'CRM-Aufgabe', 'Tâche CRM'],
            'customerSatisfaction.field.crmTaskCreated' => ['Richiamata creata entro 24 ore', 'Follow-up created within 24 hours', 'Rückruf innerhalb von 24 Stunden erstellt', 'Rappel créé sous 24 heures'],
            'customerSatisfaction.field.nextStatus' => ['Nuovo stato', 'New status', 'Neuer Status', 'Nouveau statut'],
            'customerSatisfaction.field.rootCause' => ['Causa radice', 'Root cause', 'Grundursache', 'Cause racine'],
            'customerSatisfaction.field.resolutionSummary' => ['Riepilogo della risoluzione', 'Resolution summary', 'Lösungszusammenfassung', 'Résumé de la résolution'],
            'customerSatisfaction.field.code' => ['Codice', 'Code', 'Code', 'Code'],
            'customerSatisfaction.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'customerSatisfaction.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'customerSatisfaction.field.triggerEvent' => ['Evento scatenante', 'Trigger event', 'Auslösendes Ereignis', 'Événement déclencheur'],
            'customerSatisfaction.field.channels' => ['Canali', 'Channels', 'Kanäle', 'Canaux'],
            'customerSatisfaction.field.channel' => ['Canale', 'Channel', 'Kanal', 'Canal'],
            'customerSatisfaction.field.delayHours' => ['Ritardo (ore)', 'Delay (hours)', 'Verzögerung (Stunden)', 'Délai (heures)'],
            'customerSatisfaction.field.cooldownDays' => ['Pausa tra sondaggi (giorni)', 'Cooldown (days)', 'Sperrfrist (Tage)', 'Pause entre enquêtes (jours)'],
            'customerSatisfaction.field.detractorThreshold' => ['Soglia detrattore', 'Detractor threshold', 'Detraktoren-Schwelle', 'Seuil détracteur'],
            'customerSatisfaction.field.slaHours' => ['SLA (ore)', 'SLA (hours)', 'SLA (Stunden)', 'SLA (heures)'],
            'customerSatisfaction.field.allowVoice' => ['Consenti commento vocale', 'Allow voice comment', 'Sprachkommentar erlauben', 'Autoriser le commentaire vocal'],
            'customerSatisfaction.field.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'customerSatisfaction.field.customerTier' => ['Fascia cliente', 'Customer tier', 'Kundenstufe', 'Niveau client'],
            'customerSatisfaction.field.minAmount' => ['Importo minimo', 'Minimum amount', 'Mindestbetrag', 'Montant minimum'],
            'customerSatisfaction.field.subject' => ['Oggetto', 'Subject', 'Betreff', 'Objet'],
            'customerSatisfaction.field.body' => ['Testo del messaggio', 'Message text', 'Nachrichtentext', 'Texte du message'],
            'customerSatisfaction.field.oneClickLabel' => ['Testo sopra i punteggi a un clic', 'Text above the one-click scores', 'Text über den Ein-Klick-Bewertungen', 'Texte au-dessus des notes en un clic'],
            'customerSatisfaction.field.template' => ['Modello', 'Template', 'Vorlage', 'Modèle'],
            'customerSatisfaction.field.scheduledFor' => ['Programmato per', 'Scheduled for', 'Geplant für', 'Planifié pour'],
            'customerSatisfaction.field.respondedAt' => ['Risposta del', 'Answered at', 'Beantwortet am', 'Répondu le'],
            'customerSatisfaction.field.band' => ['Fascia', 'Band', 'Gruppe', 'Groupe'],
            'customerSatisfaction.field.comment' => ['Commento', 'Comment', 'Kommentar', 'Commentaire'],
            'customerSatisfaction.field.noComment' => ['Solo punteggio, nessun commento', 'Score only, no comment', 'Nur Bewertung, kein Kommentar', 'Note seule, sans commentaire'],
            'customerSatisfaction.field.sentiment' => ['Sentiment', 'Sentiment', 'Stimmung', 'Sentiment'],
            'customerSatisfaction.field.submittedVia' => ['Ricevuto tramite', 'Received via', 'Eingegangen über', 'Reçu via'],
            'customerSatisfaction.filter.detractorsOnly' => ['Solo detrattori', 'Detractors only', 'Nur Detraktoren', 'Détracteurs uniquement'],
            'customerSatisfaction.filter.othersOnly' => ['Senza detrattori', 'Without detractors', 'Ohne Detraktoren', 'Sans détracteurs'],

            'customerSatisfaction.kpi.nps' => ['NPS', 'NPS', 'NPS', 'NPS'],
            'customerSatisfaction.kpi.npsBreakdown' => ['Promotori, passivi e detrattori', 'Promoters, passives and detractors', 'Promotoren, Passive und Detraktoren', 'Promoteurs, passifs et détracteurs'],
            'customerSatisfaction.kpi.csat' => ['CSAT', 'CSAT', 'CSAT', 'CSAT'],
            'customerSatisfaction.kpi.ces' => ['CES (sforzo medio)', 'CES (average effort)', 'CES (durchschnittlicher Aufwand)', 'CES (effort moyen)'],
            'customerSatisfaction.kpi.average' => ['Media', 'Average', 'Durchschnitt', 'Moyenne'],
            'customerSatisfaction.kpi.lowEffort' => ['Basso sforzo', 'Low effort', 'Geringer Aufwand', 'Faible effort'],
            'customerSatisfaction.kpi.responses' => ['risposte', 'responses', 'Antworten', 'réponses'],
            'customerSatisfaction.kpi.responseRate' => ['Tasso di risposta', 'Response rate', 'Antwortquote', 'Taux de réponse'],
            'customerSatisfaction.kpi.slaCompliance' => ['Rispetto SLA 24h', '24h SLA compliance', 'Einhaltung SLA 24h', 'Respect du SLA 24h'],
            'customerSatisfaction.kpi.resolutionRate' => ['Risoluzione closed-loop', 'Closed-loop resolution', 'Closed-Loop-Lösung', 'Résolution closed-loop'],
            'customerSatisfaction.kpi.openCases' => ['Casi detrattori aperti', 'Open detractor cases', 'Offene Detraktoren-Fälle', 'Dossiers détracteurs ouverts'],
            'customerSatisfaction.kpi.overdue' => ['Oltre SLA', 'Over SLA', 'SLA überschritten', 'SLA dépassé'],

            'customerSatisfaction.sla.left' => ['{hours} h {minutes} min rimanenti', '{hours} h {minutes} min left', 'Noch {hours} h {minutes} min', '{hours} h {minutes} min restantes'],
            'customerSatisfaction.sla.late' => ['In ritardo di {hours} h {minutes} min', '{hours} h {minutes} min late', '{hours} h {minutes} min überfällig', 'En retard de {hours} h {minutes} min'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'customerSatisfaction.action.apply' => ['Applica', 'Apply', 'Anwenden', 'Appliquer'],
            'customerSatisfaction.action.generateReport' => ['Genera report ISO 9001', 'Generate ISO 9001 report', 'ISO-9001-Bericht erstellen', 'Générer le rapport ISO 9001'],
            'customerSatisfaction.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'customerSatisfaction.action.assign' => ['Assegna', 'Assign', 'Zuweisen', 'Attribuer'],
            'customerSatisfaction.action.updateCase' => ['Aggiorna caso', 'Update case', 'Fall aktualisieren', 'Mettre à jour le dossier'],
            'customerSatisfaction.action.installDefaults' => ['Installa modelli predefiniti', 'Install stock templates', 'Standardvorlagen installieren', 'Installer les modèles par défaut'],
            'customerSatisfaction.action.newTemplate' => ['Nuovo modello', 'New template', 'Neue Vorlage', 'Nouveau modèle'],
            'customerSatisfaction.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'customerSatisfaction.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'customerSatisfaction.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'customerSatisfaction.action.processDue' => ['Invia ora i sondaggi in scadenza', 'Send the due surveys now', 'Fällige Umfragen jetzt senden', 'Envoyer maintenant les enquêtes échues'],
            'customerSatisfaction.action.playVoice' => ['Ascolta la nota vocale', 'Play the voice note', 'Sprachnachricht abspielen', 'Écouter la note vocale'],
            'customerSatisfaction.confirm.deleteTemplate' => ['Eliminare il modello? Invii e risposte restano archiviati.', 'Delete the template? Dispatches and answers are kept.', 'Vorlage löschen? Versand und Antworten bleiben erhalten.', 'Supprimer le modèle ? Les envois et les réponses sont conservés.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'customerSatisfaction.dashboard.subtitle' => ['Soddisfazione dei clienti secondo UNI EN ISO 9001:2015 § 9.1.2', 'Customer satisfaction under UNI EN ISO 9001:2015 § 9.1.2', 'Kundenzufriedenheit nach UNI EN ISO 9001:2015 § 9.1.2', 'Satisfaction des clients selon UNI EN ISO 9001:2015 § 9.1.2'],
            'customerSatisfaction.recovery.subtitle' => ['Detrattori da richiamare entro 24 ore: NPS ≤ 6, CSAT ≤ 2, CES ≥ 5', 'Detractors to call back within 24 hours: NPS ≤ 6, CSAT ≤ 2, CES ≥ 5', 'Detraktoren, die innerhalb von 24 Stunden zurückzurufen sind: NPS ≤ 6, CSAT ≤ 2, CES ≥ 5', 'Détracteurs à rappeler sous 24 heures : NPS ≤ 6, CSAT ≤ 2, CES ≥ 5'],
            'customerSatisfaction.recovery.drawerTitle' => ['Caso di recupero', 'Recovery case', 'Rückgewinnungsfall', 'Dossier de récupération'],
            'customerSatisfaction.templates.subtitle' => ['Strumenti NPS, CSAT e CES, eventi ERP, pausa anti-fatica (60 giorni) e testi in cinque lingue', 'NPS, CSAT and CES instruments, ERP events, survey fatigue cooldown (60 days) and texts in five locales', 'NPS-, CSAT- und CES-Instrumente, ERP-Ereignisse, Sperrfrist gegen Umfragemüdigkeit (60 Tage) und Texte in fünf Sprachvarianten', 'Instruments NPS, CSAT et CES, événements ERP, pause anti-fatigue (60 jours) et textes en cinq variantes linguistiques'],
            'customerSatisfaction.templates.newTitle' => ['Nuovo modello di sondaggio', 'New survey template', 'Neue Umfragevorlage', 'Nouveau modèle d’enquête'],
            'customerSatisfaction.templates.editTitle' => ['Modifica modello di sondaggio', 'Edit survey template', 'Umfragevorlage bearbeiten', 'Modifier le modèle d’enquête'],
            'customerSatisfaction.dispatches.subtitle' => ['Stato degli inviti, sondaggi limitati dalle regole anti-fatica e risposte con commenti testuali e vocali', 'Invitation status, surveys held back by the fatigue rules and answers with text and voice comments', 'Einladungsstatus, durch die Regeln gegen Umfragemüdigkeit zurückgehaltene Umfragen und Antworten mit Text- und Sprachkommentaren', 'État des invitations, enquêtes retenues par les règles anti-fatigue et réponses avec commentaires texte et vocaux'],
            'customerSatisfaction.tab.dispatches' => ['Invii', 'Dispatches', 'Versand', 'Envois'],
            'customerSatisfaction.tab.responses' => ['Risposte', 'Answers', 'Antworten', 'Réponses'],

            'customerSatisfaction.section.trend' => ['Andamento nel tempo', 'Trend over time', 'Verlauf', 'Évolution dans le temps'],
            'customerSatisfaction.section.breakdown' => ['Dettaglio per dimensione', 'Breakdown by dimension', 'Aufschlüsselung nach Dimension', 'Détail par dimension'],
            'customerSatisfaction.section.sentiment' => ['Sentiment dei commenti liberi', 'Sentiment of the open comments', 'Stimmung der freien Kommentare', 'Sentiment des commentaires libres'],
            'customerSatisfaction.section.topics' => ['Temi ricorrenti', 'Recurring themes', 'Wiederkehrende Themen', 'Thèmes récurrents'],
            'customerSatisfaction.section.managementReview' => ['Riesame della Direzione (ISO 9001 § 9.1.2)', 'Management review (ISO 9001 § 9.1.2)', 'Managementbewertung (ISO 9001 § 9.1.2)', 'Revue de direction (ISO 9001 § 9.1.2)'],
            'customerSatisfaction.section.owners' => ['Responsabili del caso', 'Case owners', 'Fallverantwortliche', 'Responsables du dossier'],
            'customerSatisfaction.section.workflow' => ['Avanzamento e risoluzione', 'Progress and resolution', 'Fortschritt und Lösung', 'Avancement et résolution'],
            'customerSatisfaction.section.throttling' => ['Invio e pausa anti-fatica', 'Sending and fatigue cooldown', 'Versand und Sperrfrist', 'Envoi et pause anti-fatigue'],
            'customerSatisfaction.section.filters' => ['Filtri della regola', 'Rule filters', 'Regelfilter', 'Filtres de la règle'],
            'customerSatisfaction.section.dimensions' => ['Dimensioni predefinite', 'Default dimensions', 'Standarddimensionen', 'Dimensions par défaut'],
            'customerSatisfaction.section.texts' => ['Testi e domande di approfondimento per lingua', 'Texts and follow-up questions per locale', 'Texte und Folgefragen pro Sprachvariante', 'Textes et questions de suivi par variante linguistique'],

            'customerSatisfaction.hint.iso9001' => ['Il report congela gli indicatori del periodo (NPS, CSAT, CES, SLA 24h, azioni correttive) per il Riesame della Direzione e si esporta in CSV, JSON o stampa.', 'The report freezes the indicators of the period (NPS, CSAT, CES, 24h SLA, corrective actions) for the management review and exports to CSV, JSON or print.', 'Der Bericht friert die Kennzahlen des Zeitraums ein (NPS, CSAT, CES, SLA 24h, Korrekturmassnahmen) für die Managementbewertung und wird als CSV, JSON oder Druck exportiert.', 'Le rapport fige les indicateurs de la période (NPS, CSAT, CES, SLA 24h, actions correctives) pour la revue de direction et s’exporte en CSV, JSON ou impression.'],
            'customerSatisfaction.hint.filters' => ['Elenchi separati da virgola. Un campo vuoto non filtra; l’importo minimo si confronta con il valore dell’evento.', 'Comma-separated lists. An empty field does not filter; the minimum amount is compared with the value of the event.', 'Kommagetrennte Listen. Ein leeres Feld filtert nicht; der Mindestbetrag wird mit dem Wert des Ereignisses verglichen.', 'Listes séparées par des virgules. Un champ vide ne filtre pas ; le montant minimum est comparé à la valeur de l’événement.'],
            'customerSatisfaction.hint.placeholder' => ['Usi {customer_name} nel testo per il nome del cliente. Le lingue senza testo usano l’inglese.', 'Use {customer_name} in the text for the customer name. Locales without a text fall back to English.', 'Verwenden Sie {customer_name} im Text für den Kundennamen. Sprachvarianten ohne Text verwenden Englisch.', 'Utilisez {customer_name} dans le texte pour le nom du client. Les variantes sans texte utilisent l’anglais.'],

            'customerSatisfaction.empty.trend' => ['Nessuna risposta nel periodo', 'No answers in the period', 'Keine Antworten im Zeitraum', 'Aucune réponse sur la période'],
            'customerSatisfaction.empty.sentiment' => ['Nessun commento analizzato', 'No comments analysed', 'Keine Kommentare analysiert', 'Aucun commentaire analysé'],
            'customerSatisfaction.empty.cases' => ['Nessun caso di recupero', 'No recovery cases', 'Keine Rückgewinnungsfälle', 'Aucun dossier de récupération'],
            'customerSatisfaction.empty.templates' => ['Nessun modello: installi quelli predefiniti o ne crei uno', 'No templates: install the stock ones or create one', 'Keine Vorlagen: Standardvorlagen installieren oder eine erstellen', 'Aucun modèle : installez ceux par défaut ou créez-en un'],
            'customerSatisfaction.empty.dispatches' => ['Nessun invio', 'No dispatches', 'Kein Versand', 'Aucun envoi'],
            'customerSatisfaction.empty.responses' => ['Nessuna risposta', 'No answers', 'Keine Antworten', 'Aucune réponse'],

            'customerSatisfaction.notification.detractor_recovery_opened' => ['Un cliente ha dato un punteggio da detrattore: richiamarlo entro 24 ore', 'A customer gave a detractor score: call them back within 24 hours', 'Ein Kunde hat eine Detraktoren-Bewertung abgegeben: innerhalb von 24 Stunden zurückrufen', 'Un client a donné une note de détracteur : le rappeler sous 24 heures'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'customerSatisfaction.message.saved' => ['Salvato.', 'Saved.', 'Gespeichert.', 'Enregistré.'],
            'customerSatisfaction.message.reportGenerated' => ['Report ISO 9001 generato.', 'ISO 9001 report generated.', 'ISO-9001-Bericht erstellt.', 'Rapport ISO 9001 généré.'],
            'customerSatisfaction.message.processed' => ['Inviati {sent}, falliti {failed}.', 'Sent {sent}, failed {failed}.', '{sent} gesendet, {failed} fehlgeschlagen.', '{sent} envoyés, {failed} échoués.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function publicPage(): array
    {
        return [
            'customerSatisfaction.public.title' => ['Il suo parere conta', 'Your opinion matters', 'Ihre Meinung zählt', 'Votre avis compte'],
            'customerSatisfaction.public.language' => ['Lingua', 'Language', 'Sprache', 'Langue'],
            'customerSatisfaction.public.pickScore.nps' => ['Quanto è probabile che consigli la nostra azienda a un collega o a un amico? (0 = per niente, 10 = molto)', 'How likely are you to recommend our company to a colleague or a friend? (0 = not at all, 10 = extremely)', 'Wie wahrscheinlich ist es, dass Sie unser Unternehmen einem Kollegen oder Freund weiterempfehlen? (0 = gar nicht, 10 = sehr)', 'Quelle est la probabilité que vous recommandiez notre entreprise à un collègue ou à un ami ? (0 = pas du tout, 10 = très)'],
            'customerSatisfaction.public.pickScore.csat' => ['Quanto è soddisfatto della nostra prestazione? (1 = per niente, 5 = molto)', 'How satisfied are you with our service? (1 = not at all, 5 = very)', 'Wie zufrieden sind Sie mit unserer Leistung? (1 = gar nicht, 5 = sehr)', 'Dans quelle mesure êtes-vous satisfait de notre prestation ? (1 = pas du tout, 5 = très)'],
            'customerSatisfaction.public.pickScore.ces' => ['Quanto è stato facile risolvere la sua esigenza con noi? (1 = molto facile, 7 = molto difficile)', 'How easy was it to get your need solved with us? (1 = very easy, 7 = very difficult)', 'Wie einfach war es, Ihr Anliegen mit uns zu lösen? (1 = sehr einfach, 7 = sehr schwierig)', 'Dans quelle mesure a-t-il été facile de résoudre votre besoin avec nous ? (1 = très facile, 7 = très difficile)'],
            'customerSatisfaction.public.pickScoreLabel' => ['Scelga un punteggio', 'Choose a score', 'Wählen Sie eine Bewertung', 'Choisissez une note'],
            'customerSatisfaction.public.recorded' => ['Grazie! Abbiamo registrato la sua valutazione: {score}.', 'Thank you! We recorded your rating: {score}.', 'Danke! Wir haben Ihre Bewertung erfasst: {score}.', 'Merci ! Nous avons enregistré votre note : {score}.'],
            'customerSatisfaction.public.defaultPrompt' => ['Vuole dirci di più?', 'Would you like to tell us more?', 'Möchten Sie uns mehr sagen?', 'Souhaitez-vous nous en dire plus ?'],
            'customerSatisfaction.public.commentLabel' => ['Il suo commento', 'Your comment', 'Ihr Kommentar', 'Votre commentaire'],
            'customerSatisfaction.public.commentPlaceholder' => ['Scriva qui il suo commento (facoltativo)', 'Write your comment here (optional)', 'Schreiben Sie hier Ihren Kommentar (optional)', 'Écrivez ici votre commentaire (facultatif)'],
            'customerSatisfaction.public.record' => ['Registra una nota vocale', 'Record a voice note', 'Sprachnachricht aufnehmen', 'Enregistrer une note vocale'],
            'customerSatisfaction.public.recording' => ['Registrazione in corso: {seconds} s', 'Recording: {seconds} s', 'Aufnahme läuft: {seconds} s', 'Enregistrement en cours : {seconds} s'],
            'customerSatisfaction.public.stopRecording' => ['Ferma', 'Stop', 'Stopp', 'Arrêter'],
            'customerSatisfaction.public.uploadAudio' => ['Oppure carichi un file audio', 'Or upload an audio file', 'Oder laden Sie eine Audiodatei hoch', 'Ou téléversez un fichier audio'],
            'customerSatisfaction.public.discardVoice' => ['Elimina la nota vocale', 'Discard the voice note', 'Sprachnachricht verwerfen', 'Supprimer la note vocale'],
            'customerSatisfaction.public.send' => ['Invia il commento', 'Send the comment', 'Kommentar senden', 'Envoyer le commentaire'],
            'customerSatisfaction.public.thanks' => ['Grazie per il suo tempo: il suo parere ci aiuta a migliorare.', 'Thank you for your time: your feedback helps us improve.', 'Danke für Ihre Zeit: Ihr Feedback hilft uns, besser zu werden.', 'Merci pour votre temps : votre avis nous aide à nous améliorer.'],
            'customerSatisfaction.public.optOut' => ['Non voglio più ricevere sondaggi', 'I do not want to receive surveys any more', 'Ich möchte keine Umfragen mehr erhalten', 'Je ne souhaite plus recevoir d’enquêtes'],
            'customerSatisfaction.public.optedOut' => ['Non riceverà più sondaggi di soddisfazione.', 'You will not receive satisfaction surveys any more.', 'Sie erhalten keine Zufriedenheitsumfragen mehr.', 'Vous ne recevrez plus d’enquêtes de satisfaction.'],
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
