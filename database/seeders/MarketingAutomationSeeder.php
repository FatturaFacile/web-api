<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\MarketingAutomation\Models\MarketingBlueprint;

/**
 * Mod 155 — single source of truth for every piece of Marketing Automation copy that is not a UI label: compliance
 * footers and notices (CH LCSl/nLPD, IT GDPR Art. 130), unsubscribe pages, RFM segment presets, starter MJML templates
 * and journey blueprints, in it, de, fr and en. Idempotent: global rows (tenant_id null) are updated in place.
 */
final class MarketingAutomationSeeder extends Seeder
{
    public const array LOCALES = ['it', 'de', 'fr', 'en'];

    public function run(): void
    {
        foreach ($this->texts() as $code => $values) {
            $this->upsert('text', $code, $this->wrap($values, 'value'), []);
        }

        foreach ($this->segmentPresets() as $code => $preset) {
            $this->upsert('segment_preset', $code, [
                'it' => ['name' => $preset['name'][0], 'description' => $preset['description'][0]],
                'en' => ['name' => $preset['name'][1], 'description' => $preset['description'][1]],
                'de' => ['name' => $preset['name'][2], 'description' => $preset['description'][2]],
                'fr' => ['name' => $preset['name'][3], 'description' => $preset['description'][3]],
            ], ['criteria' => $preset['criteria'], 'auto_refresh_hourly' => $preset['auto_refresh_hourly']]);
        }

        foreach ($this->templates() as $code => $template) {
            $this->upsert('email_template', $code, [
                'it' => ['name' => $template['name'][0]], 'en' => ['name' => $template['name'][1]],
                'de' => ['name' => $template['name'][2]], 'fr' => ['name' => $template['name'][3]],
            ], ['template' => $template['template']]);
        }

        foreach ($this->journeys() as $code => $journey) {
            $this->upsert('journey', $code, [
                'it' => ['name' => $journey['name'][0], 'description' => $journey['description'][0]],
                'en' => ['name' => $journey['name'][1], 'description' => $journey['description'][1]],
                'de' => ['name' => $journey['name'][2], 'description' => $journey['description'][2]],
                'fr' => ['name' => $journey['name'][3], 'description' => $journey['description'][3]],
            ], ['trigger_type' => $journey['trigger_type'], 'channel' => $journey['channel'], 'steps' => $journey['steps']]);
        }
    }

    /**
     * @param  array<string, array<string, string>>  $texts
     * @param  array<string, mixed>  $payload
     */
    private function upsert(string $kind, string $code, array $texts, array $payload): void
    {
        MarketingBlueprint::updateOrCreateGlobal(['kind' => $kind, 'code' => $code], ['texts' => $texts, 'payload' => $payload, 'is_active' => true]);
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $values  it, en, de, fr
     * @return array<string, array<string, string>>
     */
    private function wrap(array $values, string $key): array
    {
        return ['it' => [$key => $values[0]], 'en' => [$key => $values[1]], 'de' => [$key => $values[2]], 'fr' => [$key => $values[3]]];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}> it, en, de, fr
     */
    private function texts(): array
    {
        return [
            'footer.sender_identity' => [
                'Mittente: {{sender_name}}, {{sender_address}}.',
                'Sender: {{sender_name}}, {{sender_address}}.',
                'Absender: {{sender_name}}, {{sender_address}}.',
                'Expéditeur : {{sender_name}}, {{sender_address}}.',
            ],
            'footer.unsubscribe' => [
                'Non desideri più ricevere queste comunicazioni? <a href="{{unsubscribe_url}}">Annulla l\'iscrizione con un clic</a>.',
                'Do not want these messages any more? <a href="{{unsubscribe_url}}">Unsubscribe with one click</a>.',
                'Möchten Sie diese Mitteilungen nicht mehr erhalten? <a href="{{unsubscribe_url}}">Mit einem Klick abmelden</a>.',
                'Vous ne souhaitez plus recevoir ces messages ? <a href="{{unsubscribe_url}}">Désinscrivez-vous en un clic</a>.',
            ],
            'footer.privacy_ch' => [
                'Ricevi questo messaggio in base al tuo consenso o in qualità di cliente (art. 3 cpv. 1 lett. o LCSl). I tuoi dati sono trattati secondo la nLPD; puoi opporti in ogni momento.',
                'You receive this message based on your consent or as an existing customer (Art. 3 para. 1 lit. o UWG/LCSl). Your data is processed under the nFADP (nLPD); you can object at any time.',
                'Sie erhalten diese Nachricht aufgrund Ihrer Einwilligung oder als bestehender Kunde (Art. 3 Abs. 1 lit. o UWG). Ihre Daten werden nach dem nDSG bearbeitet; Sie können jederzeit widersprechen.',
                'Vous recevez ce message sur la base de votre consentement ou en tant que client existant (art. 3 al. 1 let. o LCD). Vos données sont traitées conformément à la nLPD ; vous pouvez vous y opposer à tout moment.',
            ],
            'footer.privacy_it' => [
                'Ricevi questo messaggio in base al tuo consenso (art. 130 D.Lgs. 196/2003 e GDPR) oppure come cliente per prodotti analoghi (art. 130 comma 4). Puoi revocare il consenso in ogni momento.',
                'You receive this message based on your consent (Art. 130 D.Lgs. 196/2003 and GDPR) or as a customer for similar products (Art. 130 para. 4). You can withdraw consent at any time.',
                'Sie erhalten diese Nachricht aufgrund Ihrer Einwilligung (Art. 130 D.Lgs. 196/2003 und DSGVO) oder als Kunde für ähnliche Produkte (Art. 130 Abs. 4). Sie können die Einwilligung jederzeit widerrufen.',
                'Vous recevez ce message sur la base de votre consentement (art. 130 D.Lgs. 196/2003 et RGPD) ou en tant que client pour des produits analogues (art. 130 al. 4). Vous pouvez retirer votre consentement à tout moment.',
            ],
            'footer.chat_optout' => [
                'Per non ricevere più messaggi promozionali: {{unsubscribe_url}}',
                'To stop receiving promotional messages: {{unsubscribe_url}}',
                'Um keine Werbenachrichten mehr zu erhalten: {{unsubscribe_url}}',
                'Pour ne plus recevoir de messages promotionnels : {{unsubscribe_url}}',
            ],
            'unsubscribe.title' => ['Iscrizione annullata', 'You are unsubscribed', 'Abmeldung bestätigt', 'Désinscription confirmée'],
            'unsubscribe.done' => [
                'Non riceverai più comunicazioni promozionali da noi. La tua richiesta è stata registrata.',
                'You will no longer receive promotional messages from us. Your request has been recorded.',
                'Sie erhalten von uns keine Werbenachrichten mehr. Ihre Anfrage wurde registriert.',
                'Vous ne recevrez plus de messages promotionnels de notre part. Votre demande a été enregistrée.',
            ],
            'unsubscribe.invalid' => [
                'Il link di annullamento non è valido o è scaduto.',
                'The unsubscribe link is not valid or has expired.',
                'Der Abmeldelink ist ungültig oder abgelaufen.',
                'Le lien de désinscription n\'est pas valide ou a expiré.',
            ],
            'consent.double_opt_in_subject' => [
                'Conferma la tua iscrizione', 'Confirm your subscription', 'Bestätigen Sie Ihre Anmeldung', 'Confirmez votre inscription',
            ],
            'consent.double_opt_in_body' => [
                'Conferma la tua iscrizione per ricevere le nostre novità.',
                'Confirm your subscription to receive our news.',
                'Bestätigen Sie Ihre Anmeldung, um unsere Neuigkeiten zu erhalten.',
                'Confirmez votre inscription pour recevoir nos actualités.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function segmentPresets(): array
    {
        return [
            'champions' => [
                'name' => ['Campioni', 'Champions', 'Champions', 'Champions'],
                'description' => ['Clienti recenti, frequenti e di alto valore', 'Recent, frequent, high-value customers', 'Aktuelle, häufige Kunden mit hohem Wert', 'Clients récents, fréquents et à forte valeur'],
                'criteria' => ['rfm_labels' => ['champions'], 'exclude_insolvent' => true],
                'auto_refresh_hourly' => true,
            ],
            'loyal' => [
                'name' => ['Fedeli', 'Loyal', 'Treue Kunden', 'Fidèles'],
                'description' => ['Clienti che ordinano con regolarità', 'Customers who order regularly', 'Kunden, die regelmässig bestellen', 'Clients qui commandent régulièrement'],
                'criteria' => ['rfm_labels' => ['loyal'], 'exclude_insolvent' => true],
                'auto_refresh_hourly' => true,
            ],
            'at_risk' => [
                'name' => ['A Rischio Abbandono', 'At Risk', 'Abwanderungsgefährdet', 'À risque'],
                'description' => ['Buoni clienti che non ordinano da tempo', 'Good customers who have not ordered for a while', 'Gute Kunden ohne Bestellung seit längerer Zeit', 'Bons clients qui n\'ont pas commandé depuis un moment'],
                'criteria' => ['rfm_labels' => ['at_risk'], 'exclude_insolvent' => true],
                'auto_refresh_hourly' => true,
            ],
            'lost' => [
                'name' => ['Persi', 'Lost', 'Verlorene Kunden', 'Perdus'],
                'description' => ['Clienti inattivi da oltre un anno', 'Customers inactive for over a year', 'Seit über einem Jahr inaktive Kunden', 'Clients inactifs depuis plus d\'un an'],
                'criteria' => ['rfm_labels' => ['lost', 'hibernating'], 'exclude_insolvent' => true],
                'auto_refresh_hourly' => false,
            ],
            'dormant_60_no_arrears' => [
                'name' => ['Clienti Dormienti 60gg Senza Insoluti', 'Dormant 60 Days, No Arrears', 'Ruhende Kunden 60 Tage ohne Ausstände', 'Clients dormants 60 j sans impayés'],
                'description' => ['Nessun ordine da 60 giorni e nessuno scaduto aperto', 'No order for 60 days and no open overdue amount', 'Seit 60 Tagen keine Bestellung und keine offenen Ausstände', 'Aucune commande depuis 60 jours et aucun impayé ouvert'],
                'criteria' => ['recency_days_min' => 60, 'exclude_insolvent' => true, 'max_overdue_balance' => 0],
                'auto_refresh_hourly' => true,
            ],
            'spare_parts_kit_11_months' => [
                'name' => ['Kit Ricambi Macchinari 11 Mesi', 'Machinery Spare-Parts Kit, 11 Months', 'Ersatzteil-Kit Maschinen 11 Monate', 'Kit pièces de rechange machines 11 mois'],
                'description' => ['Clienti che hanno acquistato un macchinario circa 11 mesi fa: kit ricambi annuale', 'Customers who bought machinery about 11 months ago: annual spare-parts kit', 'Kunden, die vor ca. 11 Monaten eine Maschine gekauft haben: jährliches Ersatzteil-Kit', 'Clients ayant acheté une machine il y a environ 11 mois : kit annuel de pièces'],
                'criteria' => ['purchased_window_months' => ['min' => 10, 'max' => 12], 'exclude_insolvent' => true],
                'auto_refresh_hourly' => true,
            ],
        ];
    }

    /**
     * @return array<string, array{name: array<int, string>, template: array<string, mixed>}>
     */
    private function templates(): array
    {
        return [
            'newsletter_basic' => [
                'name' => ['Newsletter base', 'Basic newsletter', 'Einfacher Newsletter', 'Newsletter de base'],
                'template' => [
                    'subject' => $this->loc('Le novità del mese', 'This month\'s news', 'Die Neuigkeiten des Monats', 'Les nouveautés du mois'),
                    'preheader' => $this->loc('Scopri le nostre ultime novità', 'Discover our latest news', 'Entdecken Sie unsere neuesten Neuigkeiten', 'Découvrez nos dernières nouveautés'),
                    'blocks' => [
                        ['id' => 'h1', 'type' => 'heading', 'props' => ['text' => $this->loc('Ciao {{first_name}}', 'Hello {{first_name}}', 'Guten Tag {{first_name}}', 'Bonjour {{first_name}}')]],
                        ['id' => 't1', 'type' => 'text', 'props' => ['text' => $this->loc('Ecco le novità selezionate per te.', 'Here is the news selected for you.', 'Hier sind die für Sie ausgewählten Neuigkeiten.', 'Voici les nouveautés sélectionnées pour vous.')]],
                        ['id' => 'b1', 'type' => 'button', 'props' => ['href' => 'https://example.com', 'label' => $this->loc('Scopri di più', 'Learn more', 'Mehr erfahren', 'En savoir plus')]],
                    ],
                ],
            ],
            'dormant_win_back' => [
                'name' => ['Riattivazione clienti dormienti', 'Dormant customer win-back', 'Reaktivierung ruhender Kunden', 'Réactivation des clients dormants'],
                'template' => [
                    'subject' => $this->loc('Ci manchi, {{first_name}}', 'We miss you, {{first_name}}', 'Wir vermissen Sie, {{first_name}}', 'Vous nous manquez, {{first_name}}'),
                    'preheader' => $this->loc('Un\'offerta pensata per te', 'An offer made for you', 'Ein Angebot nur für Sie', 'Une offre pensée pour vous'),
                    'blocks' => [
                        ['id' => 'h1', 'type' => 'heading', 'props' => ['text' => $this->loc('È passato un po\' di tempo', 'It has been a while', 'Es ist eine Weile her', 'Cela fait un moment')]],
                        ['id' => 't1', 'type' => 'text', 'props' => ['text' => $this->loc('Scopri il listino aggiornato e le condizioni riservate ai clienti.', 'See the updated price list and the conditions reserved for customers.', 'Entdecken Sie die aktualisierte Preisliste und die Kundenkonditionen.', 'Découvrez le tarif mis à jour et les conditions réservées aux clients.')]],
                        ['id' => 'b1', 'type' => 'button', 'props' => ['href' => 'https://example.com/price-list', 'label' => $this->loc('Vedi il listino', 'See the price list', 'Preisliste ansehen', 'Voir le tarif')]],
                    ],
                ],
            ],
            'spare_parts_kit' => [
                'name' => ['Kit ricambi annuale', 'Annual spare-parts kit', 'Jährliches Ersatzteil-Kit', 'Kit annuel de pièces de rechange'],
                'template' => [
                    'subject' => $this->loc('È ora del kit ricambi annuale', 'Time for the annual spare-parts kit', 'Zeit für das jährliche Ersatzteil-Kit', 'C\'est le moment du kit annuel de pièces'),
                    'preheader' => $this->loc('Mantieni il tuo macchinario al meglio', 'Keep your machine at its best', 'Halten Sie Ihre Maschine in Bestform', 'Gardez votre machine au meilleur de sa forme'),
                    'blocks' => [
                        ['id' => 'h1', 'type' => 'heading', 'props' => ['text' => $this->loc('Il tuo macchinario compie quasi un anno', 'Your machine is almost one year old', 'Ihre Maschine ist fast ein Jahr alt', 'Votre machine a presque un an')]],
                        ['id' => 'p1', 'type' => 'product', 'props' => ['name' => $this->loc('Kit ricambi', 'Spare-parts kit', 'Ersatzteil-Kit', 'Kit de pièces'), 'description' => $this->loc('Importa qui un prodotto dal catalogo ERP.', 'Import an ERP catalog product here.', 'Importieren Sie hier ein Produkt aus dem ERP-Katalog.', 'Importez ici un produit du catalogue ERP.'), 'cta_label' => $this->loc('Richiedi offerta', 'Request a quote', 'Angebot anfordern', 'Demander un devis'), 'url' => 'https://example.com']],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function journeys(): array
    {
        $call = static fn (array $subject): array => ['task_type' => 'call', 'due_in_days' => 2, 'priority' => 'high', 'subject' => $subject];

        return [
            'dormant_reactivation' => [
                'name' => ['Riattivazione clienti dormienti 60gg', 'Dormant 60-day reactivation', 'Reaktivierung nach 60 Tagen', 'Réactivation après 60 jours'],
                'description' => ['Email, attesa di 3 giorni, poi chiamata dell\'agente se ha cliccato ma non ha ordinato', 'Email, 3-day wait, then an agent call if the customer clicked but did not order', 'E-Mail, 3 Tage Wartezeit, dann Anruf des Aussendienstlers bei Klick ohne Bestellung', 'E-mail, attente de 3 jours, puis appel de l\'agent en cas de clic sans commande'],
                'trigger_type' => 'customer_dormant_60_days',
                'channel' => 'multi_channel_journey',
                'steps' => [
                    ['step_key' => 'mail', 'type' => 'send_email', 'is_entry' => true, 'next_step_key' => 'wait', 'labels' => $this->loc('Invia email di riattivazione', 'Send win-back email', 'Reaktivierungs-E-Mail senden', 'Envoyer l\'e-mail de réactivation')],
                    ['step_key' => 'wait', 'type' => 'wait', 'config' => ['days' => 3], 'next_step_key' => 'check', 'labels' => $this->loc('Attendi 3 giorni', 'Wait 3 days', '3 Tage warten', 'Attendre 3 jours')],
                    ['step_key' => 'check', 'type' => 'condition', 'config' => ['type' => 'clicked_not_ordered'], 'true_step_key' => 'task', 'false_step_key' => 'end', 'labels' => $this->loc('Ha cliccato il listino e non ha ordinato?', 'Clicked the price list and did not order?', 'Preisliste geklickt, nicht bestellt?', 'A cliqué sur le tarif sans commander ?')],
                    ['step_key' => 'task', 'type' => 'crm_task', 'config' => $call($this->loc('Richiama il cliente dopo la campagna di riattivazione', 'Call the customer after the reactivation campaign', 'Kunden nach der Reaktivierungskampagne anrufen', 'Rappeler le client après la campagne de réactivation')), 'next_step_key' => 'end', 'labels' => $this->loc('Crea attività per l\'agente di zona', 'Create task for the area agent', 'Aufgabe für den Gebietsvertreter erstellen', 'Créer une tâche pour l\'agent de zone')],
                    ['step_key' => 'end', 'type' => 'end', 'labels' => $this->loc('Fine', 'End', 'Ende', 'Fin')],
                ],
            ],
            'abandoned_quote' => [
                'name' => ['Preventivo non convertito', 'Abandoned quote', 'Nicht umgesetztes Angebot', 'Devis non converti'],
                'description' => ['Promemoria WhatsApp dopo l\'invio del preventivo, poi attività per l\'agente', 'WhatsApp reminder after the quote was sent, then an agent task', 'WhatsApp-Erinnerung nach dem Angebot, dann Aufgabe für den Vertreter', 'Rappel WhatsApp après l\'envoi du devis, puis tâche pour l\'agent'],
                'trigger_type' => 'abandoned_cart',
                'channel' => 'multi_channel_journey',
                'steps' => [
                    ['step_key' => 'chat', 'type' => 'send_whatsapp', 'is_entry' => true, 'config' => ['message' => $this->loc('Ha avuto modo di vedere il nostro preventivo? Siamo a disposizione.', 'Did you get a chance to review our quote? We are happy to help.', 'Konnten Sie unser Angebot ansehen? Wir helfen gerne.', 'Avez-vous pu consulter notre devis ? Nous restons à votre disposition.')], 'next_step_key' => 'wait', 'labels' => $this->loc('Promemoria WhatsApp', 'WhatsApp reminder', 'WhatsApp-Erinnerung', 'Rappel WhatsApp')],
                    ['step_key' => 'wait', 'type' => 'wait', 'config' => ['days' => 2], 'next_step_key' => 'check', 'labels' => $this->loc('Attendi 2 giorni', 'Wait 2 days', '2 Tage warten', 'Attendre 2 jours')],
                    ['step_key' => 'check', 'type' => 'condition', 'config' => ['type' => 'not_ordered'], 'true_step_key' => 'task', 'false_step_key' => 'end', 'labels' => $this->loc('Nessun ordine?', 'No order yet?', 'Noch keine Bestellung?', 'Pas encore de commande ?')],
                    ['step_key' => 'task', 'type' => 'crm_task', 'config' => $call($this->loc('Seguire il preventivo inviato', 'Follow up the quote sent', 'Gesendetes Angebot nachfassen', 'Relancer le devis envoyé')), 'next_step_key' => 'end', 'labels' => $this->loc('Crea attività per l\'agente', 'Create agent task', 'Vertreter-Aufgabe erstellen', 'Créer une tâche pour l\'agent')],
                    ['step_key' => 'end', 'type' => 'end', 'labels' => $this->loc('Fine', 'End', 'Ende', 'Fin')],
                ],
            ],
            'warranty_expiring' => [
                'name' => ['Garanzia in scadenza', 'Warranty expiring', 'Garantie läuft ab', 'Garantie arrivant à échéance'],
                'description' => ['Email con offerta di estensione garanzia/assistenza 30 giorni prima della scadenza', 'Email with a warranty/service extension offer 30 days before expiry', 'E-Mail mit Garantie-/Serviceverlängerung 30 Tage vor Ablauf', 'E-mail avec offre d\'extension de garantie/service 30 jours avant l\'échéance'],
                'trigger_type' => 'warranty_expiring',
                'channel' => 'email_dem',
                'steps' => [
                    ['step_key' => 'mail', 'type' => 'send_email', 'is_entry' => true, 'next_step_key' => 'end', 'labels' => $this->loc('Invia offerta estensione garanzia', 'Send warranty extension offer', 'Garantieverlängerung anbieten', 'Envoyer l\'offre d\'extension de garantie')],
                    ['step_key' => 'end', 'type' => 'end', 'labels' => $this->loc('Fine', 'End', 'Ende', 'Fin')],
                ],
            ],
            'post_fair_followup' => [
                'name' => ['Follow-up post fiera', 'Post-fair lead follow-up', 'Messe-Nachfassen', 'Suivi après salon'],
                'description' => ['Email di ringraziamento e chiamata dell\'agente per i lead raccolti in fiera', 'Thank-you email and an agent call for the leads collected at the fair', 'Dankes-E-Mail und Anruf des Vertreters für Messe-Leads', 'E-mail de remerciement et appel de l\'agent pour les leads du salon'],
                'trigger_type' => 'post_fair_lead_followup',
                'channel' => 'multi_channel_journey',
                'steps' => [
                    ['step_key' => 'mail', 'type' => 'send_email', 'is_entry' => true, 'next_step_key' => 'wait', 'labels' => $this->loc('Email di ringraziamento', 'Thank-you email', 'Dankes-E-Mail', 'E-mail de remerciement')],
                    ['step_key' => 'wait', 'type' => 'wait', 'config' => ['days' => 2], 'next_step_key' => 'task', 'labels' => $this->loc('Attendi 2 giorni', 'Wait 2 days', '2 Tage warten', 'Attendre 2 jours')],
                    ['step_key' => 'task', 'type' => 'crm_task', 'config' => $call($this->loc('Chiamare il contatto conosciuto in fiera', 'Call the contact met at the fair', 'Auf der Messe getroffenen Kontakt anrufen', 'Appeler le contact rencontré au salon')), 'next_step_key' => 'end', 'labels' => $this->loc('Crea attività per l\'agente', 'Create agent task', 'Vertreter-Aufgabe erstellen', 'Créer une tâche pour l\'agent')],
                    ['step_key' => 'end', 'type' => 'end', 'labels' => $this->loc('Fine', 'End', 'Ende', 'Fin')],
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function loc(string $it, string $en, string $de, string $fr): array
    {
        return ['it' => $it, 'en' => $en, 'de' => $de, 'fr' => $fr];
    }
}
