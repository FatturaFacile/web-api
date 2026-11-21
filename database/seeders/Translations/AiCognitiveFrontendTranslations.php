<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * Frontend strings of the AI Services and Cognitive Automation Layer (Mod 093): the Cognitive Review Workbench, the Predictive
 * Insights and Anomaly Center and the AI governance settings (it, en, de, fr). Merged by FrontendTranslationSeeder::dictionary().
 * Each entry lists the four languages in the order it, en, de, fr.
 */
final class AiCognitiveFrontendTranslations
{
    private const array Languages = ['it' => 0, 'en' => 1, 'de' => 2, 'fr' => 3];

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = self::Languages[$locale] ?? throw new InvalidArgumentException("Unknown locale [{$locale}].");
        $dictionary = [];

        foreach (self::definitions() as $key => $texts) {
            $dictionary['AI_COG.'.$key] = $texts[$index];
        }

        return $dictionary;
    }

    /**
     * Every key (without the `AI_COG.` prefix) with its four texts in the order it, en, de, fr.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            // Navigation and titles
            'TITLE' => ['Intelligenza artificiale', 'Artificial intelligence', 'Künstliche Intelligenz', 'Intelligence artificielle'],
            'NAV_WORKBENCH' => ['Revisione AI', 'AI review', 'KI-Prüfung', 'Revue IA'],
            'NAV_INSIGHTS' => ['Previsioni e anomalie', 'Insights and anomalies', 'Prognosen und Anomalien', 'Prévisions et anomalies'],
            'NAV_SETTINGS' => ['Impostazioni AI', 'AI settings', 'KI-Einstellungen', 'Paramètres IA'],

            // Workbench
            'WORKBENCH_TITLE' => ['Workbench di revisione cognitiva', 'Cognitive review workbench', 'Kognitive Prüfstation', 'Poste de revue cognitive'],
            'WORKBENCH_SUBTITLE' => ['Verifica, correggi e approva le proposte dell\'AI prima che diventino registrazioni.', 'Check, correct and approve the proposals of the AI before they become records.', 'Prüfen, korrigieren und genehmigen Sie die Vorschläge der KI, bevor sie zu Buchungen werden.', 'Vérifiez, corrigez et approuvez les propositions de l\'IA avant qu\'elles deviennent des écritures.'],
            'ALL_KINDS' => ['Tutte', 'All', 'Alle', 'Toutes'],
            'QUEUE' => ['Coda di revisione', 'Review queue', 'Prüfwarteschlange', 'File de revue'],
            'REFRESH' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'SHOW_DECIDED' => ['Mostra decise', 'Show decided', 'Entschiedene anzeigen', 'Afficher les décisions'],
            'EMPTY_QUEUE' => ['Nessuna proposta da rivedere.', 'Nothing to review.', 'Nichts zu prüfen.', 'Rien à examiner.'],
            'SELECT_SUGGESTION' => ['Seleziona una proposta dalla coda.', 'Select a proposal from the queue.', 'Wählen Sie einen Vorschlag aus der Warteschlange.', 'Sélectionnez une proposition dans la file.'],
            'SOURCE' => ['Fonte', 'Source', 'Quelle', 'Source'],
            'NO_SOURCE_TEXT' => ['Questa proposta non ha un testo di origine.', 'This proposal has no source text.', 'Dieser Vorschlag hat keinen Quelltext.', 'Cette proposition n\'a pas de texte source.'],
            'PRODUCED_BY' => ['Prodotto da', 'Produced by', 'Erzeugt von', 'Produit par'],
            'PROPOSED_ACTION' => ['Azione proposta', 'Proposed action', 'Vorgeschlagene Aktion', 'Action proposée'],
            'REASONING' => ['Motivazione', 'Reasoning', 'Begründung', 'Justification'],
            'APPROVE' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'EDIT_AND_APPROVE' => ['Modifica e approva', 'Edit and approve', 'Bearbeiten und genehmigen', 'Modifier et approuver'],
            'ACKNOWLEDGE' => ['Presa visione', 'Acknowledge', 'Zur Kenntnis nehmen', 'Prendre acte'],
            'REJECT' => ['Rifiuta', 'Reject', 'Ablehnen', 'Rejeter'],
            'DISMISS' => ['Ignora', 'Dismiss', 'Verwerfen', 'Ignorer'],
            'RESET' => ['Ripristina la proposta', 'Reset to the proposal', 'Auf Vorschlag zurücksetzen', 'Revenir à la proposition'],
            'REJECT_REASON' => ['Motivo del rifiuto', 'Reason for rejecting', 'Grund der Ablehnung', 'Motif du rejet'],
            'REJECT_CONFIRM' => ['Conferma il rifiuto', 'Confirm rejection', 'Ablehnung bestätigen', 'Confirmer le rejet'],
            'APPROVED' => ['Proposta approvata.', 'Proposal approved.', 'Vorschlag genehmigt.', 'Proposition approuvée.'],
            'REJECTED' => ['Proposta rifiutata.', 'Proposal rejected.', 'Vorschlag abgelehnt.', 'Proposition rejetée.'],
            'OPEN_RESULT' => ['Apri il documento creato', 'Open the created record', 'Erstellten Datensatz öffnen', 'Ouvrir l\'enregistrement créé'],
            'OPEN_SUBJECT' => ['Apri il record interessato', 'Open the record concerned', 'Betroffenen Datensatz öffnen', 'Ouvrir l\'enregistrement concerné'],
            'PRIORITY_HIGH' => ['Alta', 'High', 'Hoch', 'Haute'],
            'PRIORITY_NORMAL' => ['Normale', 'Normal', 'Normal', 'Normale'],
            'PRIORITY_LOW' => ['Bassa', 'Low', 'Niedrig', 'Basse'],

            // Kinds
            'KIND_SALES_QUOTE' => ['Offerta di vendita', 'Sales quote', 'Verkaufsofferte', 'Offre de vente'],
            'KIND_PURCHASE_ORDER' => ['Ordine d\'acquisto', 'Purchase order', 'Bestellung', 'Commande d\'achat'],
            'KIND_SUPPORT_TICKET' => ['Ticket di supporto', 'Support ticket', 'Support-Ticket', 'Ticket de support'],
            'KIND_CONTACT' => ['Contatto', 'Contact', 'Kontakt', 'Contact'],
            'KIND_ACCOUNTING_CODING' => ['Imputazione contabile', 'Accounting coding', 'Kontierung', 'Imputation comptable'],
            'KIND_RECONCILIATION_MATCH' => ['Riconciliazione bancaria', 'Bank reconciliation', 'Bankabstimmung', 'Rapprochement bancaire'],
            'KIND_ANOMALY' => ['Anomalia', 'Anomaly', 'Anomalie', 'Anomalie'],
            'KIND_LATE_PAYMENT_RISK' => ['Rischio di pagamento in ritardo', 'Late payment risk', 'Risiko verspäteter Zahlung', 'Risque de retard de paiement'],
            'KIND_CHURN_RISK' => ['Rischio di perdita cliente', 'Churn risk', 'Abwanderungsrisiko', 'Risque de perte client'],
            'KIND_REORDER' => ['Riordino', 'Reorder', 'Nachbestellung', 'Réapprovisionnement'],
            'KIND_CASH_FLOW_ALERT' => ['Allerta liquidità', 'Cash flow alert', 'Liquiditätswarnung', 'Alerte de trésorerie'],

            // Sources and statuses
            'SOURCE_EMAIL' => ['Email', 'Email', 'E-Mail', 'E-mail'],
            'SOURCE_RFQ' => ['Richiesta di offerta', 'Request for quotation', 'Offertanfrage', 'Demande d\'offre'],
            'SOURCE_DELIVERY_NOTE' => ['Bolla di consegna', 'Delivery note', 'Lieferschein', 'Bon de livraison'],
            'SOURCE_SUPPORT_TICKET' => ['Messaggio di supporto', 'Support message', 'Support-Nachricht', 'Message de support'],
            'SOURCE_DOCUMENT' => ['Documento', 'Document', 'Dokument', 'Document'],
            'SOURCE_BANK_TRANSACTION' => ['Movimento bancario', 'Bank transaction', 'Bankbewegung', 'Mouvement bancaire'],
            'SOURCE_EXPENSE_RECEIPT' => ['Giustificativo di spesa', 'Expense receipt', 'Spesenbeleg', 'Justificatif de frais'],
            'SOURCE_SYSTEM' => ['Analisi automatica', 'Automatic analysis', 'Automatische Analyse', 'Analyse automatique'],
            'STATUS_PENDING' => ['Da rivedere', 'Pending', 'Offen', 'En attente'],
            'STATUS_AUTO_APPROVED' => ['Approvata automaticamente', 'Auto-approved', 'Automatisch genehmigt', 'Approuvée automatiquement'],
            'STATUS_APPROVED' => ['Approvata', 'Approved', 'Genehmigt', 'Approuvée'],
            'STATUS_EDITED_APPROVED' => ['Modificata e approvata', 'Edited and approved', 'Bearbeitet und genehmigt', 'Modifiée et approuvée'],
            'STATUS_REJECTED' => ['Rifiutata', 'Rejected', 'Abgelehnt', 'Rejetée'],
            'STATUS_EXPIRED' => ['Scaduta', 'Expired', 'Abgelaufen', 'Expirée'],
            'STATUS_FAILED' => ['Fallita', 'Failed', 'Fehlgeschlagen', 'Échouée'],

            // Insights
            'INSIGHTS_TITLE' => ['Previsioni e anomalie', 'Predictive insights and anomalies', 'Prognosen und Anomalien', 'Prévisions et anomalies'],
            'INSIGHTS_SUBTITLE' => ['Liquidità a 30, 60 e 90 giorni, rischio di pagamento, riordini e controlli di conformità.', 'Cash at 30, 60 and 90 days, payment risk, reorders and compliance checks.', 'Liquidität in 30, 60 und 90 Tagen, Zahlungsrisiko, Nachbestellungen und Compliance-Prüfungen.', 'Trésorerie à 30, 60 et 90 jours, risque de paiement, réapprovisionnements et contrôles de conformité.'],
            'RUN_ANALYSIS' => ['Esegui analisi ora', 'Run analysis now', 'Analyse jetzt ausführen', 'Lancer l\'analyse maintenant'],
            'ANALYSIS_DONE' => ['Analisi completata.', 'Analysis completed.', 'Analyse abgeschlossen.', 'Analyse terminée.'],
            'STAT_URGENT' => ['Urgenti', 'Urgent', 'Dringend', 'Urgents'],
            'STAT_RECOMMENDATIONS' => ['Raccomandazioni', 'Recommendations', 'Empfehlungen', 'Recommandations'],
            'STAT_ANOMALIES' => ['Anomalie', 'Anomalies', 'Anomalien', 'Anomalies'],
            'STAT_LOWEST_CASH' => ['Liquidità minima attesa', 'Lowest expected cash', 'Tiefste erwartete Liquidität', 'Trésorerie minimale attendue'],
            'CASH_FLOW' => ['Previsione di cassa', 'Cash flow forecast', 'Liquiditätsprognose', 'Prévision de trésorerie'],
            'HORIZON_90' => ['13 settimane', '13 weeks', '13 Wochen', '13 semaines'],
            'HORIZON_365' => ['12 mesi', '12 months', '12 Monate', '12 mois'],
            'NEGATIVE_FROM' => ['Nello scenario prudente il saldo diventa negativo dal', 'In the pessimistic scenario the balance turns negative from', 'Im vorsichtigen Szenario wird der Saldo negativ ab dem', 'Dans le scénario prudent le solde devient négatif à partir du'],
            'CHART_FOOTNOTE' => ['Banda tra scenario prudente (10%) e ottimistico (90%)', 'Band between the pessimistic (10%) and optimistic (90%) scenario', 'Bandbreite zwischen vorsichtigem (10 %) und optimistischem (90 %) Szenario', 'Bande entre le scénario prudent (10 %) et optimiste (90 %)'],
            'NO_FORECAST' => ['Nessuna previsione disponibile: i modelli girano ogni notte.', 'No forecast yet: the models run every night.', 'Noch keine Prognose: die Modelle laufen jede Nacht.', 'Pas encore de prévision : les modèles tournent chaque nuit.'],
            'PAYMENT_RISK' => ['Clienti per rischio di pagamento', 'Customers by payment risk', 'Kunden nach Zahlungsrisiko', 'Clients par risque de paiement'],
            'RISK_TIER' => ['Rischio', 'Risk', 'Risiko', 'Risque'],
            'ON_TIME_PROBABILITY' => ['Probabilità di pagare in tempo', 'Probability of paying on time', 'Wahrscheinlichkeit pünktlicher Zahlung', 'Probabilité de payer à temps'],
            'TIER_LOW' => ['Basso', 'Low', 'Niedrig', 'Faible'],
            'TIER_MODERATE' => ['Moderato', 'Moderate', 'Mässig', 'Modéré'],
            'TIER_HIGH' => ['Alto', 'High', 'Hoch', 'Élevé'],
            'TIER_CRITICAL' => ['Critico', 'Critical', 'Kritisch', 'Critique'],
            'RECOMMENDATIONS' => ['Raccomandazioni', 'Recommendations', 'Empfehlungen', 'Recommandations'],
            'NO_RECOMMENDATIONS' => ['Nessuna raccomandazione aperta.', 'No open recommendations.', 'Keine offenen Empfehlungen.', 'Aucune recommandation ouverte.'],
            'ANOMALIES' => ['Anomalie e controlli di conformità', 'Anomalies and compliance checks', 'Anomalien und Compliance-Prüfungen', 'Anomalies et contrôles de conformité'],
            'NO_ANOMALIES' => ['Nessuna anomalia aperta.', 'No open anomalies.', 'Keine offenen Anomalien.', 'Aucune anomalie ouverte.'],
            'REVIEW_IN_WORKBENCH' => ['Apri nel workbench', 'Open in the workbench', 'In der Prüfstation öffnen', 'Ouvrir dans le poste de revue'],
            'ACKNOWLEDGED' => ['Presa visione registrata.', 'Acknowledgement recorded.', 'Kenntnisnahme erfasst.', 'Prise d\'acte enregistrée.'],
            'DISMISSED' => ['Segnalazione ignorata.', 'Alert dismissed.', 'Meldung verworfen.', 'Alerte ignorée.'],
            'DISMISSED_REASON' => ['Ignorata dall\'utente dal centro previsioni', 'Dismissed by the user in the insights center', 'Vom Benutzer im Prognosezentrum verworfen', 'Ignorée par l\'utilisateur dans le centre de prévisions'],

            // Settings
            'SETTINGS_TITLE' => ['Governance e provider AI', 'AI governance and providers', 'KI-Governance und Anbieter', 'Gouvernance et fournisseurs IA'],
            'SETTINGS_SUBTITLE' => ['Scegli dove girano i modelli, quali dati possono ricevere e quando l\'AI può agire senza una persona.', 'Choose where the models run, which data they may receive and when the AI may act without a person.', 'Legen Sie fest, wo die Modelle laufen, welche Daten sie erhalten dürfen und wann die KI ohne Person handeln darf.', 'Choisissez où tournent les modèles, quelles données ils peuvent recevoir et quand l\'IA peut agir sans personne.'],
            'EXTERNAL_OFF_TITLE' => ['I provider esterni non sono autorizzati', 'External providers are not allowed', 'Externe Anbieter sind nicht erlaubt', 'Les fournisseurs externes ne sont pas autorisés'],
            'EXTERNAL_OFF_TEXT' => ['I dati restano nell\'installazione: gira solo il modello locale (Ollama). Per usare OpenAI, Anthropic, Gemini o Azure OpenAI con la propria chiave, abilita prima i provider esterni nelle impostazioni dei dati personali.', 'Data stays inside the installation: only the local model (Ollama) runs. To use OpenAI, Anthropic, Gemini or Azure OpenAI with your own key, first allow external providers in the privacy settings.', 'Die Daten bleiben in der Installation: nur das lokale Modell (Ollama) läuft. Um OpenAI, Anthropic, Gemini oder Azure OpenAI mit dem eigenen Schlüssel zu nutzen, erlauben Sie zuerst externe Anbieter in den Datenschutzeinstellungen.', 'Les données restent dans l\'installation : seul le modèle local (Ollama) tourne. Pour utiliser OpenAI, Anthropic, Gemini ou Azure OpenAI avec votre propre clé, autorisez d\'abord les fournisseurs externes dans les paramètres de confidentialité.'],
            'BLOCKED_PROVIDERS' => ['Alcuni provider esterni sono attivi ma non possono girare finché i provider esterni non sono autorizzati.', 'Some external providers are switched on but cannot run until external providers are allowed.', 'Einige externe Anbieter sind aktiviert, können aber erst laufen, wenn externe Anbieter erlaubt sind.', 'Certains fournisseurs externes sont activés mais ne peuvent pas tourner tant que les fournisseurs externes ne sont pas autorisés.'],
            'PROVIDERS' => ['Provider', 'Providers', 'Anbieter', 'Fournisseurs'],
            'PROVIDER_OLLAMA' => ['Ollama (locale)', 'Ollama (local)', 'Ollama (lokal)', 'Ollama (local)'],
            'PROVIDER_OPENAI' => ['OpenAI', 'OpenAI', 'OpenAI', 'OpenAI'],
            'PROVIDER_ANTHROPIC' => ['Anthropic Claude', 'Anthropic Claude', 'Anthropic Claude', 'Anthropic Claude'],
            'PROVIDER_GEMINI' => ['Google Gemini', 'Google Gemini', 'Google Gemini', 'Google Gemini'],
            'PROVIDER_AZURE_OPENAI' => ['Azure OpenAI', 'Azure OpenAI', 'Azure OpenAI', 'Azure OpenAI'],
            'LOCAL_PRIVACY' => ['Locale, nessun dato esce', 'Local, no data leaves', 'Lokal, keine Daten verlassen das System', 'Local, aucune donnée ne sort'],
            'HOSTED_BYOK' => ['Cloud, con la tua chiave', 'Cloud, with your own key', 'Cloud, mit eigenem Schlüssel', 'Cloud, avec votre propre clé'],
            'EXTERNAL_NOT_ALLOWED' => ['Provider esterni non autorizzati', 'External providers not allowed', 'Externe Anbieter nicht erlaubt', 'Fournisseurs externes non autorisés'],
            'ENABLED' => ['Attivo', 'Enabled', 'Aktiv', 'Actif'],
            'DEFAULT_PROVIDER' => ['Provider predefinito', 'Default provider', 'Standardanbieter', 'Fournisseur par défaut'],
            'ENDPOINT' => ['Indirizzo del servizio', 'Service address', 'Dienstadresse', 'Adresse du service'],
            'MODEL' => ['Modello', 'Model', 'Modell', 'Modèle'],
            'LIGHT_MODEL' => ['Modello leggero', 'Light model', 'Leichtes Modell', 'Modèle léger'],
            'EMBEDDING_MODEL' => ['Modello di embedding', 'Embedding model', 'Embedding-Modell', 'Modèle d\'embedding'],
            'AZURE_DEPLOYMENT' => ['Deployment Azure', 'Azure deployment', 'Azure-Deployment', 'Déploiement Azure'],
            'AZURE_API_VERSION' => ['Versione API Azure', 'Azure API version', 'Azure-API-Version', 'Version d\'API Azure'],
            'API_KEY' => ['Chiave API', 'API key', 'API-Schlüssel', 'Clé API'],
            'KEY_STORED' => ['Chiave salvata (lascia vuoto per mantenerla)', 'Key stored (leave empty to keep it)', 'Schlüssel gespeichert (leer lassen, um ihn zu behalten)', 'Clé enregistrée (laisser vide pour la conserver)'],
            'KEY_NONE' => ['Nessuna chiave', 'No key', 'Kein Schlüssel', 'Aucune clé'],
            'MAX_CLASSIFICATION' => ['Dati massimi consentiti', 'Highest data allowed', 'Höchste erlaubte Daten', 'Données maximales autorisées'],
            'CLASS_PUBLIC' => ['Pubblici', 'Public', 'Öffentlich', 'Publiques'],
            'CLASS_INTERNAL' => ['Interni', 'Internal', 'Intern', 'Internes'],
            'CLASS_CONFIDENTIAL_PII' => ['Riservati con dati personali', 'Confidential with personal data', 'Vertraulich mit Personendaten', 'Confidentielles avec données personnelles'],
            'PII_MASKING' => ['Maschera i dati personali', 'Mask personal data', 'Personendaten maskieren', 'Masquer les données personnelles'],
            'MONTHLY_TOKEN_BUDGET' => ['Budget mensile di token', 'Monthly token budget', 'Monatliches Token-Budget', 'Budget mensuel de jetons'],
            'RATE_LIMIT' => ['Richieste al minuto', 'Requests per minute', 'Anfragen pro Minute', 'Requêtes par minute'],
            'SAVE' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'SAVED' => ['Impostazioni salvate.', 'Settings saved.', 'Einstellungen gespeichert.', 'Paramètres enregistrés.'],
            'AUTOMATION_RULES' => ['Approvazione automatica', 'Automatic approval', 'Automatische Freigabe', 'Approbation automatique'],
            'AUTOMATION_HELP' => ['L\'AI agisce da sola solo se l\'approvazione automatica è attiva, la confidenza raggiunge la soglia e l\'importo resta sotto il limite; altrimenti la proposta va in revisione.', 'The AI acts on its own only if automatic approval is on, the confidence reaches the threshold and the amount stays below the limit; otherwise the proposal goes to review.', 'Die KI handelt nur selbständig, wenn die automatische Freigabe aktiv ist, die Konfidenz die Schwelle erreicht und der Betrag unter dem Limit bleibt; sonst geht der Vorschlag in die Prüfung.', 'L\'IA n\'agit seule que si l\'approbation automatique est active, que la confiance atteint le seuil et que le montant reste sous la limite ; sinon la proposition passe en revue.'],
            'SAMPLE_CONFIDENCE' => ['Confidenza di esempio', 'Sample confidence', 'Beispiel-Konfidenz', 'Confiance d\'exemple'],
            'SAMPLE_AMOUNT' => ['Importo di esempio', 'Sample amount', 'Beispielbetrag', 'Montant d\'exemple'],
            'WORKFLOW' => ['Flusso', 'Workflow', 'Ablauf', 'Flux'],
            'AUTO_APPROVE' => ['Approva da sola', 'Approve automatically', 'Automatisch genehmigen', 'Approuver automatiquement'],
            'MIN_CONFIDENCE' => ['Confidenza minima', 'Minimum confidence', 'Minimale Konfidenz', 'Confiance minimale'],
            'MAX_AUTO_AMOUNT' => ['Importo massimo automatico', 'Maximum automatic amount', 'Maximaler automatischer Betrag', 'Montant automatique maximal'],
            'CLASSIFICATION' => ['Classificazione dei dati', 'Data classification', 'Datenklassifizierung', 'Classification des données'],
            'SAMPLE_OUTCOME' => ['Esito sull\'esempio', 'Outcome on the sample', 'Ergebnis am Beispiel', 'Résultat sur l\'exemple'],
            'OUTCOME_AUTO' => ['Automatica', 'Automatic', 'Automatisch', 'Automatique'],
            'OUTCOME_REVIEW' => ['In revisione', 'Review', 'Prüfung', 'Revue'],
            'OUTCOME_OFF' => ['Disattivato', 'Off', 'Aus', 'Désactivé'],
            'WORKFLOW_EXTRACTION_SALES_QUOTE' => ['Email in offerta', 'Email to quote', 'E-Mail zu Offerte', 'E-mail en offre'],
            'WORKFLOW_EXTRACTION_PURCHASE_ORDER' => ['Bolla in ordine d\'acquisto', 'Delivery note to purchase order', 'Lieferschein zu Bestellung', 'Bon de livraison en commande'],
            'WORKFLOW_EXTRACTION_SUPPORT_TICKET' => ['Messaggio in ticket', 'Message to ticket', 'Nachricht zu Ticket', 'Message en ticket'],
            'WORKFLOW_EXTRACTION_CONTACT' => ['Firma in contatto', 'Signature to contact', 'Signatur zu Kontakt', 'Signature en contact'],
            'WORKFLOW_ACCOUNTING_CODING' => ['Imputazione contabile', 'Accounting coding', 'Kontierung', 'Imputation comptable'],
            'WORKFLOW_RECONCILIATION' => ['Riconciliazione bancaria', 'Bank reconciliation', 'Bankabstimmung', 'Rapprochement bancaire'],
            'WORKFLOW_ANOMALY_GUARD' => ['Controllo anomalie', 'Anomaly guard', 'Anomalie-Prüfung', 'Contrôle des anomalies'],
            'WORKFLOW_PREDICTIVE_INSIGHT' => ['Raccomandazioni predittive', 'Predictive recommendations', 'Prädiktive Empfehlungen', 'Recommandations prédictives'],
            'USAGE' => ['Utilizzo del mese', 'Usage this month', 'Nutzung im Monat', 'Utilisation du mois'],
            'TOTAL_TOKENS' => ['Token totali', 'Total tokens', 'Tokens gesamt', 'Jetons au total'],
            'TOTAL_COST' => ['Costo totale', 'Total cost', 'Gesamtkosten', 'Coût total'],
            'CALLS' => ['chiamate', 'calls', 'Aufrufe', 'appels'],
            'TOKENS' => ['token', 'tokens', 'Tokens', 'jetons'],
            'FAILURES' => ['errori', 'failures', 'Fehler', 'échecs'],
            'CHAIN_VALID' => ['Registro di audit integro', 'Audit trail intact', 'Audit-Trail unverändert', 'Journal d\'audit intact'],
            'CHAIN_BROKEN' => ['Registro di audit alterato', 'Audit trail altered', 'Audit-Trail verändert', 'Journal d\'audit altéré'],
            'VERIFY_CHAIN' => ['Verifica la catena di audit', 'Verify the audit chain', 'Audit-Kette prüfen', 'Vérifier la chaîne d\'audit'],

            // Fields of the proposals
            'FIELD_CONTACT_ID' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'FIELD_SUPPLIER_ID' => ['Fornitore', 'Supplier', 'Lieferant', 'Fournisseur'],
            'FIELD_CUSTOMER_ID' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'FIELD_CUSTOMER' => ['Cliente indicato', 'Customer named', 'Genannter Kunde', 'Client indiqué'],
            'FIELD_SUPPLIER' => ['Fornitore indicato', 'Supplier named', 'Genannter Lieferant', 'Fournisseur indiqué'],
            'FIELD_CURRENCY' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'FIELD_VALID_UNTIL' => ['Valida fino al', 'Valid until', 'Gültig bis', 'Valable jusqu\'au'],
            'FIELD_ORDER_DATE' => ['Data ordine', 'Order date', 'Bestelldatum', 'Date de commande'],
            'FIELD_EXPECTED_DELIVERY_DATE' => ['Consegna prevista', 'Expected delivery', 'Erwartete Lieferung', 'Livraison prévue'],
            'FIELD_NOTES' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'FIELD_ITEMS' => ['Righe', 'Items', 'Positionen', 'Lignes'],
            'FIELD_LINES' => ['Righe', 'Lines', 'Positionen', 'Lignes'],
            'FIELD_DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'FIELD_QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'FIELD_QUANTITY_ORDERED' => ['Quantità ordinata', 'Quantity ordered', 'Bestellte Menge', 'Quantité commandée'],
            'FIELD_UNIT_PRICE' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'FIELD_TAX_RATE' => ['Aliquota IVA', 'VAT rate', 'MWST-Satz', 'Taux de TVA'],
            'FIELD_UOM' => ['Unità', 'Unit', 'Einheit', 'Unité'],
            'FIELD_TITLE' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'FIELD_PRIORITY' => ['Priorità', 'Priority', 'Priorität', 'Priorité'],
            'FIELD_CONTACT_EMAIL' => ['Email di contatto', 'Contact email', 'Kontakt-E-Mail', 'E-mail de contact'],
            'FIELD_CONTACT_PHONE' => ['Telefono di contatto', 'Contact phone', 'Kontakttelefon', 'Téléphone de contact'],
            'FIELD_TYPE' => ['Tipo', 'Type', 'Typ', 'Type'],
            'FIELD_COMPANY_NAME' => ['Ragione sociale', 'Company name', 'Firma', 'Raison sociale'],
            'FIELD_FIRST_NAME' => ['Nome', 'First name', 'Vorname', 'Prénom'],
            'FIELD_LAST_NAME' => ['Cognome', 'Last name', 'Nachname', 'Nom'],
            'FIELD_EMAIL' => ['Email', 'Email', 'E-Mail', 'E-mail'],
            'FIELD_PHONE' => ['Telefono', 'Phone', 'Telefon', 'Téléphone'],
            'FIELD_VAT_NUMBER' => ['Partita IVA', 'VAT number', 'MWST-Nummer', 'Numéro de TVA'],
            'FIELD_COUNTRY' => ['Paese', 'Country', 'Land', 'Pays'],
            'FIELD_GL_ACCOUNT_ID' => ['Conto', 'Account', 'Konto', 'Compte'],
            'FIELD_ACCOUNT_CODE' => ['Codice conto', 'Account code', 'Kontonummer', 'Code de compte'],
            'FIELD_VAT_CODE_ID' => ['Codice IVA', 'VAT code', 'MWST-Code', 'Code TVA'],
            'FIELD_VAT_CODE' => ['Codice IVA', 'VAT code', 'MWST-Code', 'Code TVA'],
            'FIELD_VAT_RATE' => ['Aliquota', 'Rate', 'Satz', 'Taux'],
            'FIELD_COST_CENTER_ID' => ['Centro di costo', 'Cost center', 'Kostenstelle', 'Centre de coûts'],
            'FIELD_BANK_TRANSACTION_ID' => ['Movimento bancario', 'Bank transaction', 'Bankbewegung', 'Mouvement bancaire'],
            'FIELD_INVOICE_ID' => ['Fattura', 'Invoice', 'Rechnung', 'Facture'],
            'FIELD_INVOICE_IDS' => ['Fatture', 'Invoices', 'Rechnungen', 'Factures'],
            'FIELD_MATCH_TYPE' => ['Tipo di abbinamento', 'Match type', 'Art der Zuordnung', 'Type de rapprochement'],
            'FIELD_AMOUNT' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'FIELD_OPEN_AMOUNT' => ['Importo aperto', 'Open amount', 'Offener Betrag', 'Montant ouvert'],
            'FIELD_RULE' => ['Regola', 'Rule', 'Regel', 'Règle'],
            'FIELD_SEVERITY' => ['Gravità', 'Severity', 'Schweregrad', 'Gravité'],
            'FIELD_SUBJECT_TYPE' => ['Tipo di record', 'Record type', 'Datensatztyp', 'Type d\'enregistrement'],
            'FIELD_SUBJECT_ID' => ['Record', 'Record', 'Datensatz', 'Enregistrement'],
            'FIELD_DETAILS' => ['Dettagli', 'Details', 'Details', 'Détails'],
            'FIELD_RISK_TIER' => ['Livello di rischio', 'Risk tier', 'Risikostufe', 'Niveau de risque'],
            'FIELD_AVERAGE_DAYS_LATE' => ['Ritardo medio (giorni)', 'Average days late', 'Durchschnittliche Verspätung (Tage)', 'Retard moyen (jours)'],
            'FIELD_ON_TIME_PROBABILITY' => ['Probabilità di puntualità', 'On-time probability', 'Pünktlichkeitswahrscheinlichkeit', 'Probabilité de ponctualité'],
            'FIELD_RECOMMENDED_ACTION' => ['Azione consigliata', 'Recommended action', 'Empfohlene Aktion', 'Action recommandée'],
            'FIELD_INVOICES_PRIOR_90_DAYS' => ['Fatture nei 90 giorni precedenti', 'Invoices in the prior 90 days', 'Rechnungen in den 90 Tagen davor', 'Factures des 90 jours précédents'],
            'FIELD_INVOICES_LAST_90_DAYS' => ['Fatture negli ultimi 90 giorni', 'Invoices in the last 90 days', 'Rechnungen in den letzten 90 Tagen', 'Factures des 90 derniers jours'],
            'FIELD_REVENUE_PRIOR' => ['Fatturato precedente', 'Prior revenue', 'Früherer Umsatz', 'Chiffre d\'affaires précédent'],
            'FIELD_REVENUE_RECENT' => ['Fatturato recente', 'Recent revenue', 'Jüngster Umsatz', 'Chiffre d\'affaires récent'],
            'FIELD_STOCK_ITEM_ID' => ['Articolo', 'Item', 'Artikel', 'Article'],
            'FIELD_SKU' => ['Codice articolo', 'SKU', 'Artikelnummer', 'Référence'],
            'FIELD_NAME' => ['Nome', 'Name', 'Name', 'Nom'],
            'FIELD_AVAILABLE' => ['Disponibile', 'Available', 'Verfügbar', 'Disponible'],
            'FIELD_REORDER_POINT' => ['Punto di riordino', 'Reorder point', 'Meldebestand', 'Point de commande'],
            'FIELD_SUGGESTED_QUANTITY' => ['Quantità suggerita', 'Suggested quantity', 'Vorgeschlagene Menge', 'Quantité suggérée'],
            'FIELD_LEAD_TIME_DAYS' => ['Tempo di consegna (giorni)', 'Lead time (days)', 'Lieferzeit (Tage)', 'Délai de livraison (jours)'],
            'FIELD_FIRST_NEGATIVE_DATE' => ['Primo giorno negativo', 'First negative day', 'Erster negativer Tag', 'Premier jour négatif'],
            'FIELD_P10_BALANCE' => ['Saldo prudente', 'Pessimistic balance', 'Vorsichtiger Saldo', 'Solde prudent'],
            'FIELD_P50_BALANCE_AT_DATE' => ['Saldo atteso', 'Expected balance', 'Erwarteter Saldo', 'Solde attendu'],
            'FIELD_HORIZON_DAYS' => ['Orizzonte (giorni)', 'Horizon (days)', 'Horizont (Tage)', 'Horizon (jours)'],
            'FIELD_RUN_ID' => ['Previsione', 'Forecast run', 'Prognoselauf', 'Prévision'],
        ];
    }
}
