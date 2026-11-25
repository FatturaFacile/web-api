<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\DebtCollection\Models\InterestRate;
use Modules\DebtCollection\Models\Template;

/**
 * Reference data of the dunning module (global rows, tenant_id null), idempotent: the statutory late-payment interest
 * rate periods and the standard texts of the notices in it, en, de and fr (the regional variants it-CH, de-CH, fr-CH and
 * it-IT are served from their language). A company overrides any of them with a row of its own.
 *
 * The ECB figures are the main refinancing rate of the first day of each semester plus the 8 points of D.Lgs. 231/2002
 * as published by the Italian Ministry of Economy and Finance; the last period stays open until a newer notice is
 * entered, so the rate of the current semester must be confirmed against that notice every January and July.
 */
final class DebtCollectionReferenceSeeder extends Seeder
{
    /**
     * ECB reference rate (percent) by first day of the semester.
     *
     * @var array<string, string>
     */
    private const array EcbReferenceRates = [
        '2013-01-01' => '0.75', '2013-07-01' => '0.50', '2014-01-01' => '0.25', '2014-07-01' => '0.15',
        '2015-01-01' => '0.05', '2015-07-01' => '0.05', '2016-01-01' => '0.00', '2016-07-01' => '0.00',
        '2017-01-01' => '0.00', '2017-07-01' => '0.00', '2018-01-01' => '0.00', '2018-07-01' => '0.00',
        '2019-01-01' => '0.00', '2019-07-01' => '0.00', '2020-01-01' => '0.00', '2020-07-01' => '0.00',
        '2021-01-01' => '0.00', '2021-07-01' => '0.00', '2022-01-01' => '0.00', '2022-07-01' => '0.00',
        '2023-01-01' => '2.50', '2023-07-01' => '4.00', '2024-01-01' => '4.50', '2024-07-01' => '4.25',
        '2025-01-01' => '3.15', '2025-07-01' => '2.15', '2026-01-01' => '2.15',
    ];

    public function run(): void
    {
        $this->rates();

        foreach ($this->templates() as [$code, $locale, $jurisdiction, $subject, $body]) {
            Template::updateOrCreateGlobal(['code' => $code, 'locale' => $locale, 'jurisdiction' => $jurisdiction], ['subject' => $subject, 'body' => $body]);
        }
    }

    private function rates(): void
    {
        InterestRate::updateOrCreateGlobal(
            ['jurisdiction' => 'CH', 'kind' => 'statutory_ch', 'effective_from' => '1912-01-01'],
            ['base_rate_percent' => '5.0000', 'markup_percent' => '0.0000', 'effective_to' => null, 'notes' => 'Art. 104 CO'],
        );

        $dates = array_keys(self::EcbReferenceRates);

        foreach ($dates as $index => $from) {
            $next = $dates[$index + 1] ?? null;

            InterestRate::updateOrCreateGlobal(
                ['jurisdiction' => 'IT', 'kind' => 'ecb_it', 'effective_from' => $from],
                [
                    'base_rate_percent' => self::EcbReferenceRates[$from],
                    'markup_percent' => '8.0000',
                    'effective_to' => $next === null ? null : date('Y-m-d', (int) strtotime($next.' -1 day')),
                    'notes' => 'D.Lgs. 231/2002',
                ],
            );
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string, 4: string}> code, locale, jurisdiction, subject, body
     */
    private function templates(): array
    {
        $rows = [];
        $texts = $this->texts();

        foreach ($texts as $code => $variants) {
            foreach ($variants as $jurisdiction => $languages) {
                foreach ($languages as $locale => $text) {
                    $rows[] = [$code, $locale, $jurisdiction === '*' ? null : $jurisdiction, $text[0], $text[1]];
                }
            }
        }

        foreach ($this->labels() as $code => $languages) {
            foreach ($languages as $locale => $labels) {
                $rows[] = [$code, $locale, null, '', (string) json_encode($labels, JSON_UNESCAPED_UNICODE)];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, array<string, array{0: string, 1: string}>>> code => jurisdiction ('*' = any) => language => [subject, body]
     */
    private function texts(): array
    {
        $items = "{{items}}\n";

        return [
            'level_0' => ['*' => [
                'it' => ['Promemoria di pagamento', "Gentile {{customer_name}},\n\ndai nostri registri risulta che i seguenti importi sono scaduti e non ancora saldati:\n\n{$items}\nTotale da saldare: {{currency}} {{total}}.\nSe ha già provveduto al pagamento, La preghiamo di non tenere conto di questo promemoria.\n\nCordiali saluti\n{{company_name}}"],
                'en' => ['Payment reminder', "Dear {{customer_name}},\n\nour records show that the following amounts are overdue and still unpaid:\n\n{$items}\nTotal due: {{currency}} {{total}}.\nIf you have already paid, please disregard this reminder.\n\nKind regards\n{{company_name}}"],
                'de' => ['Zahlungserinnerung', "Sehr geehrte Damen und Herren {{customer_name}},\n\nunsere Buchhaltung weist folgende fällige und noch offene Beträge aus:\n\n{$items}\nOffener Betrag: {{currency}} {{total}}.\nSollten Sie bereits bezahlt haben, betrachten Sie diese Erinnerung bitte als gegenstandslos.\n\nFreundliche Grüsse\n{{company_name}}"],
                'fr' => ['Rappel de paiement', "Madame, Monsieur {{customer_name}},\n\nnos écritures font apparaître les montants échus suivants, non encore réglés :\n\n{$items}\nTotal dû : {{currency}} {{total}}.\nSi vous avez déjà payé, veuillez ne pas tenir compte de ce rappel.\n\nMeilleures salutations\n{{company_name}}"],
            ]],
            'level_1' => ['*' => [
                'it' => ['Primo sollecito di pagamento', "Gentile {{customer_name}},\n\nnonostante il nostro promemoria, i seguenti importi risultano ancora insoluti:\n\n{$items}\nLa invitiamo a versare {{currency}} {{total}} entro il {{new_due_date}}.\n\nCordiali saluti\n{{company_name}}"],
                'en' => ['First payment reminder', "Dear {{customer_name}},\n\ndespite our reminder, the following amounts are still unpaid:\n\n{$items}\nPlease pay {{currency}} {{total}} by {{new_due_date}}.\n\nKind regards\n{{company_name}}"],
                'de' => ['1. Mahnung', "Sehr geehrte Damen und Herren {{customer_name}},\n\ntrotz unserer Zahlungserinnerung sind folgende Beträge noch offen:\n\n{$items}\nWir bitten Sie, {{currency}} {{total}} bis zum {{new_due_date}} zu überweisen.\n\nFreundliche Grüsse\n{{company_name}}"],
                'fr' => ['1er rappel', "Madame, Monsieur {{customer_name}},\n\nmalgré notre rappel, les montants suivants restent impayés :\n\n{$items}\nNous vous prions de verser {{currency}} {{total}} jusqu'au {{new_due_date}}.\n\nMeilleures salutations\n{{company_name}}"],
            ]],
            'level_2' => ['*' => [
                'it' => ['Secondo sollecito di pagamento', "Gentile {{customer_name}},\n\nnonostante i precedenti solleciti, i seguenti importi sono ancora insoluti:\n\n{$items}\nCapitale: {{currency}} {{principal}}\nInteressi di mora ({{interest_rate}}% annuo, {{legal_basis}}): {{currency}} {{interest}}\nSpese amministrative: {{currency}} {{fee}}\nTotale: {{currency}} {{total}}\n\nIl pagamento deve pervenire entro il {{new_due_date}}.\n\nCordiali saluti\n{{company_name}}"],
                'en' => ['Second payment reminder', "Dear {{customer_name}},\n\ndespite our earlier reminders, the following amounts are still unpaid:\n\n{$items}\nPrincipal: {{currency}} {{principal}}\nDefault interest ({{interest_rate}}% p.a., {{legal_basis}}): {{currency}} {{interest}}\nAdministrative fees: {{currency}} {{fee}}\nTotal: {{currency}} {{total}}\n\nPayment must reach us by {{new_due_date}}.\n\nKind regards\n{{company_name}}"],
                'de' => ['2. Mahnung', "Sehr geehrte Damen und Herren {{customer_name}},\n\ntrotz unserer früheren Mahnungen sind folgende Beträge weiterhin offen:\n\n{$items}\nForderung: {{currency}} {{principal}}\nVerzugszins ({{interest_rate}}% p.a., {{legal_basis}}): {{currency}} {{interest}}\nMahnspesen: {{currency}} {{fee}}\nTotal: {{currency}} {{total}}\n\nDie Zahlung hat bis zum {{new_due_date}} bei uns einzugehen.\n\nFreundliche Grüsse\n{{company_name}}"],
                'fr' => ['2e rappel', "Madame, Monsieur {{customer_name}},\n\nmalgré nos précédents rappels, les montants suivants restent impayés :\n\n{$items}\nCréance : {{currency}} {{principal}}\nIntérêts moratoires ({{interest_rate}}% par an, {{legal_basis}}) : {{currency}} {{interest}}\nFrais de rappel : {{currency}} {{fee}}\nTotal : {{currency}} {{total}}\n\nLe paiement doit nous parvenir d'ici au {{new_due_date}}.\n\nMeilleures salutations\n{{company_name}}"],
            ]],
            'level_3' => [
                'CH' => [
                    'it' => ['Ultimo sollecito e diffida ad adempiere', "Gentile {{customer_name}},\n\nnonostante i nostri solleciti, i seguenti importi sono ancora insoluti:\n\n{$items}\nCapitale: {{currency}} {{principal}}\nInteressi di mora ({{interest_rate}}% annuo, {{legal_basis}}): {{currency}} {{interest}}\nSpese di richiamo: {{currency}} {{fee}}\nTotale: {{currency}} {{total}}\n\nLa diffidiamo a pagare entro il {{new_due_date}}. Trascorso inutilmente tale termine avvieremo senza ulteriore avviso la procedura di esecuzione (Betreibung, LEF) a Suo carico, con aggravio delle spese, e potremo sospendere le forniture.\n\n{{company_name}}"],
                    'en' => ['Final notice and demand for payment', "Dear {{customer_name}},\n\ndespite our reminders, the following amounts are still unpaid:\n\n{$items}\nPrincipal: {{currency}} {{principal}}\nDefault interest ({{interest_rate}}% p.a., {{legal_basis}}): {{currency}} {{interest}}\nReminder fees: {{currency}} {{fee}}\nTotal: {{currency}} {{total}}\n\nWe demand payment by {{new_due_date}}. If that deadline passes without payment we will start debt enforcement proceedings (Betreibung, DEBA) against you without further notice, at your expense, and may suspend deliveries.\n\n{{company_name}}"],
                    'de' => ['Letzte Mahnung', "Sehr geehrte Damen und Herren {{customer_name}},\n\ntrotz unserer Mahnungen sind folgende Beträge weiterhin offen:\n\n{$items}\nForderung: {{currency}} {{principal}}\nVerzugszins ({{interest_rate}}% p.a., {{legal_basis}}): {{currency}} {{interest}}\nMahnspesen: {{currency}} {{fee}}\nTotal: {{currency}} {{total}}\n\nWir fordern Sie auf, bis zum {{new_due_date}} zu bezahlen. Nach unbenutztem Fristablauf leiten wir ohne weitere Ankündigung die Betreibung (SchKG) auf Ihre Kosten ein und behalten uns die Einstellung der Lieferungen vor.\n\n{{company_name}}"],
                    'fr' => ['Dernier rappel et sommation', "Madame, Monsieur {{customer_name}},\n\nmalgré nos rappels, les montants suivants restent impayés :\n\n{$items}\nCréance : {{currency}} {{principal}}\nIntérêts moratoires ({{interest_rate}}% par an, {{legal_basis}}) : {{currency}} {{interest}}\nFrais de rappel : {{currency}} {{fee}}\nTotal : {{currency}} {{total}}\n\nNous vous sommons de payer jusqu'au {{new_due_date}}. Passé ce délai, nous engagerons sans autre avis la poursuite (LP) à vos frais et pourrons suspendre les livraisons.\n\n{{company_name}}"],
                ],
                'IT' => [
                    'it' => ['Diffida ad adempiere e messa in mora', "Gentile {{customer_name}},\n\nnonostante i nostri solleciti, i seguenti importi sono ancora insoluti:\n\n{$items}\nCapitale: {{currency}} {{principal}}\nInteressi di mora ({{interest_rate}}% annuo, {{legal_basis}}): {{currency}} {{interest}}\nSpese di gestione pratica: {{currency}} {{fee}}\nTotale: {{currency}} {{total}}\n\nLa diffidiamo ad adempiere entro il {{new_due_date}}. Decorso inutilmente tale termine agiremo in sede giudiziale, anche con ricorso per decreto ingiuntivo, con addebito di spese e competenze, e potremo sospendere le forniture.\n\n{{company_name}}"],
                    'en' => ['Formal demand and notice of default', "Dear {{customer_name}},\n\ndespite our reminders, the following amounts are still unpaid:\n\n{$items}\nPrincipal: {{currency}} {{principal}}\nDefault interest ({{interest_rate}}% p.a., {{legal_basis}}): {{currency}} {{interest}}\nCase handling fees: {{currency}} {{fee}}\nTotal: {{currency}} {{total}}\n\nWe demand performance by {{new_due_date}}. If that deadline passes without payment we will take legal action, including an application for an injunction to pay (decreto ingiuntivo), at your expense, and may suspend deliveries.\n\n{{company_name}}"],
                    'de' => ['Letzte Mahnung und Inverzugsetzung', "Sehr geehrte Damen und Herren {{customer_name}},\n\ntrotz unserer Mahnungen sind folgende Beträge weiterhin offen:\n\n{$items}\nForderung: {{currency}} {{principal}}\nVerzugszinsen ({{interest_rate}}% p.a., {{legal_basis}}): {{currency}} {{interest}}\nBearbeitungsspesen: {{currency}} {{fee}}\nTotal: {{currency}} {{total}}\n\nWir fordern Sie auf, bis zum {{new_due_date}} zu erfüllen. Nach unbenutztem Fristablauf leiten wir gerichtliche Schritte ein, auch mittels Mahnverfahren (decreto ingiuntivo), auf Ihre Kosten, und behalten uns die Einstellung der Lieferungen vor.\n\n{{company_name}}"],
                    'fr' => ['Mise en demeure', "Madame, Monsieur {{customer_name}},\n\nmalgré nos rappels, les montants suivants restent impayés :\n\n{$items}\nCréance : {{currency}} {{principal}}\nIntérêts moratoires ({{interest_rate}}% par an, {{legal_basis}}) : {{currency}} {{interest}}\nFrais de gestion du dossier : {{currency}} {{fee}}\nTotal : {{currency}} {{total}}\n\nNous vous mettons en demeure de vous exécuter jusqu'au {{new_due_date}}. Passé ce délai, nous agirons en justice, y compris par injonction de payer (decreto ingiuntivo), à vos frais, et pourrons suspendre les livraisons.\n\n{{company_name}}"],
                ],
            ],
            'messa_in_mora' => ['IT' => [
                'it' => ['Messa in mora e diffida ad adempiere', "Spett.le {{customer_name}},\n\ncon la presente, ai sensi degli artt. 1219 e 1454 c.c., {{company_name}} La mette formalmente in mora e La diffida ad adempiere al pagamento dei seguenti importi, scaduti e non pagati:\n\n{$items}\nCapitale: {{currency}} {{principal}}\nInteressi moratori ex {{legal_basis}} ({{interest_rate}}% annuo, tasso BCE + 8 punti), calcolati sui giorni effettivi di ritardo: {{currency}} {{interest}}\nSpese di recupero e gestione pratica: {{currency}} {{fee}}\nTotale: {{currency}} {{total}}\n\nIl pagamento dovrà essere eseguito entro il {{new_due_date}}. Decorso inutilmente tale termine, il contratto si intenderà risolto di diritto ove applicabile e saranno promossi, senza ulteriore avviso, i necessari giudizi, compreso il ricorso per decreto ingiuntivo ex art. 633 c.p.c., con aggravio di spese, interessi e competenze legali.\n\nLa presente è inviata a mezzo PEC con valore di comunicazione formale.\n\n{{company_name}}"],
                'en' => ['Formal notice of default (messa in mora)', "To {{customer_name}},\n\nunder articles 1219 and 1454 of the Italian Civil Code, {{company_name}} hereby formally places you in default and demands payment of the following overdue and unpaid amounts:\n\n{$items}\nPrincipal: {{currency}} {{principal}}\nLate-payment interest under {{legal_basis}} ({{interest_rate}}% p.a., ECB rate + 8 points) on the actual days of delay: {{currency}} {{interest}}\nRecovery and case handling costs: {{currency}} {{fee}}\nTotal: {{currency}} {{total}}\n\nPayment must be made by {{new_due_date}}. If that deadline passes without payment, the contract will be deemed terminated by operation of law where applicable and the necessary proceedings will be started without further notice, including an application for an injunction to pay under art. 633 of the Italian Code of Civil Procedure, at your expense.\n\nThis notice is sent by certified e-mail (PEC) as a formal communication.\n\n{{company_name}}"],
                'de' => ['Formelle Inverzugsetzung (messa in mora)', "An {{customer_name}},\n\ngemäss Art. 1219 und 1454 des italienischen Zivilgesetzbuchs setzt Sie {{company_name}} hiermit förmlich in Verzug und fordert Sie zur Zahlung der folgenden fälligen und unbezahlten Beträge auf:\n\n{$items}\nForderung: {{currency}} {{principal}}\nVerzugszinsen nach {{legal_basis}} ({{interest_rate}}% p.a., EZB-Satz + 8 Punkte) auf die effektiven Verzugstage: {{currency}} {{interest}}\nInkasso- und Bearbeitungskosten: {{currency}} {{fee}}\nTotal: {{currency}} {{total}}\n\nDie Zahlung hat bis zum {{new_due_date}} zu erfolgen. Nach unbenutztem Fristablauf gilt der Vertrag, soweit anwendbar, von Rechts wegen als aufgelöst, und es werden ohne weitere Ankündigung die erforderlichen Verfahren eingeleitet, einschliesslich des Antrags auf Zahlungsbefehl (decreto ingiuntivo, Art. 633 ital. ZPO), auf Ihre Kosten.\n\nDieses Schreiben wird per zertifizierter E-Mail (PEC) als förmliche Mitteilung versandt.\n\n{{company_name}}"],
                'fr' => ['Mise en demeure formelle (messa in mora)', "À {{customer_name}},\n\nconformément aux art. 1219 et 1454 du Code civil italien, {{company_name}} vous met formellement en demeure et vous somme de payer les montants suivants, échus et impayés :\n\n{$items}\nCréance : {{currency}} {{principal}}\nIntérêts moratoires selon {{legal_basis}} ({{interest_rate}}% par an, taux BCE + 8 points) sur les jours de retard effectifs : {{currency}} {{interest}}\nFrais de recouvrement et de gestion du dossier : {{currency}} {{fee}}\nTotal : {{currency}} {{total}}\n\nLe paiement doit être effectué jusqu'au {{new_due_date}}. Passé ce délai, le contrat sera réputé résolu de plein droit, le cas échéant, et les procédures nécessaires seront engagées sans autre avis, y compris la demande d'injonction de payer (art. 633 du Code de procédure civile italien), à vos frais.\n\nLa présente est envoyée par courriel certifié (PEC) à titre de communication formelle.\n\n{{company_name}}"],
            ]],
        ];
    }

    /**
     * Captions of the generated documents, as JSON maps stored in the body of a template.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private function labels(): array
    {
        return [
            'labels' => [
                'it' => ['to' => 'Destinatario', 'notice_number' => 'Numero', 'date' => 'Data', 'document' => 'Documento', 'due_date' => 'Scadenza', 'principal' => 'Capitale', 'days' => 'Giorni', 'interest' => 'Interessi', 'total_principal' => 'Capitale', 'total_interest' => 'Interessi di mora', 'total_fee' => 'Spese', 'total_due' => 'Totale dovuto', 'pay_by' => 'Pagamento entro'],
                'en' => ['to' => 'To', 'notice_number' => 'Number', 'date' => 'Date', 'document' => 'Document', 'due_date' => 'Due date', 'principal' => 'Principal', 'days' => 'Days', 'interest' => 'Interest', 'total_principal' => 'Principal', 'total_interest' => 'Default interest', 'total_fee' => 'Fees', 'total_due' => 'Total due', 'pay_by' => 'Pay by'],
                'de' => ['to' => 'An', 'notice_number' => 'Nummer', 'date' => 'Datum', 'document' => 'Beleg', 'due_date' => 'Fälligkeit', 'principal' => 'Forderung', 'days' => 'Tage', 'interest' => 'Zins', 'total_principal' => 'Forderung', 'total_interest' => 'Verzugszins', 'total_fee' => 'Spesen', 'total_due' => 'Total fällig', 'pay_by' => 'Zahlbar bis'],
                'fr' => ['to' => 'À', 'notice_number' => 'Numéro', 'date' => 'Date', 'document' => 'Document', 'due_date' => 'Échéance', 'principal' => 'Créance', 'days' => 'Jours', 'interest' => 'Intérêts', 'total_principal' => 'Créance', 'total_interest' => 'Intérêts moratoires', 'total_fee' => 'Frais', 'total_due' => 'Total dû', 'pay_by' => 'Payable jusqu\'au'],
            ],
            'betreibung' => [
                'it' => ['title' => 'Domanda di esecuzione (Betreibungsbegehren)', 'number' => 'Pratica', 'date' => 'Data', 'office' => 'Ufficio di esecuzione competente', 'creditor' => 'Creditore', 'debtor' => 'Debitore', 'claim' => 'Credito', 'claim_reason' => 'Titolo del credito (fattura)', 'due_date' => 'Scadenza', 'principal' => 'Capitale', 'interest_rate' => 'Tasso', 'interest' => 'Interessi', 'total_principal' => 'Capitale', 'total_interest' => 'Interessi di mora', 'total_costs' => 'Spese di richiamo', 'total_claim' => 'Totale preteso', 'interest_note' => 'Interessi di mora dal giorno successivo alla scadenza di ogni fattura, al tasso indicato (art. 104 CO), fino al saldo.', 'chronology' => 'Cronologia', 'event_invoice' => 'Fattura', 'event_due' => 'Scadenza fattura', 'event_settlement' => 'Incasso o accredito', 'event_notice' => 'Sollecito', 'level' => 'livello', 'attachments' => 'Allegati', 'attachments_text' => 'Fatture, polizze di versamento QR e solleciti sono inclusi nel fascicolo allegato (ZIP).'],
                'en' => ['title' => 'Request for debt enforcement (Betreibungsbegehren)', 'number' => 'File', 'date' => 'Date', 'office' => 'Competent enforcement office', 'creditor' => 'Creditor', 'debtor' => 'Debtor', 'claim' => 'Claim', 'claim_reason' => 'Basis of the claim (invoice)', 'due_date' => 'Due date', 'principal' => 'Principal', 'interest_rate' => 'Rate', 'interest' => 'Interest', 'total_principal' => 'Principal', 'total_interest' => 'Default interest', 'total_costs' => 'Reminder costs', 'total_claim' => 'Total claimed', 'interest_note' => 'Default interest from the day after the due date of each invoice, at the rate shown (art. 104 CO), until payment.', 'chronology' => 'Chronology', 'event_invoice' => 'Invoice', 'event_due' => 'Invoice due', 'event_settlement' => 'Payment or credit', 'event_notice' => 'Notice', 'level' => 'level', 'attachments' => 'Attachments', 'attachments_text' => 'Invoices, QR payment slips and dunning notices are included in the attached bundle (ZIP).'],
                'de' => ['title' => 'Betreibungsbegehren', 'number' => 'Dossier', 'date' => 'Datum', 'office' => 'Zuständiges Betreibungsamt', 'creditor' => 'Gläubiger', 'debtor' => 'Schuldner', 'claim' => 'Forderung', 'claim_reason' => 'Forderungsurkunde / Grund (Rechnung)', 'due_date' => 'Fälligkeit', 'principal' => 'Forderung', 'interest_rate' => 'Zinssatz', 'interest' => 'Zins', 'total_principal' => 'Forderungsbetrag', 'total_interest' => 'Verzugszins', 'total_costs' => 'Mahnkosten', 'total_claim' => 'Total der Forderung', 'interest_note' => 'Verzugszins ab dem Tag nach der Fälligkeit jeder Rechnung zum angegebenen Satz (Art. 104 OR) bis zur Zahlung.', 'chronology' => 'Chronologie', 'event_invoice' => 'Rechnung', 'event_due' => 'Rechnung fällig', 'event_settlement' => 'Zahlung oder Gutschrift', 'event_notice' => 'Mahnung', 'level' => 'Stufe', 'attachments' => 'Beilagen', 'attachments_text' => 'Rechnungen, QR-Zahlteile und Mahnungen liegen im beigefügten Dossier (ZIP).'],
                'fr' => ['title' => 'Réquisition de poursuite (Betreibungsbegehren)', 'number' => 'Dossier', 'date' => 'Date', 'office' => 'Office des poursuites compétent', 'creditor' => 'Créancier', 'debtor' => 'Débiteur', 'claim' => 'Créance', 'claim_reason' => 'Titre et cause de la créance (facture)', 'due_date' => 'Échéance', 'principal' => 'Créance', 'interest_rate' => 'Taux', 'interest' => 'Intérêts', 'total_principal' => 'Montant de la créance', 'total_interest' => 'Intérêts moratoires', 'total_costs' => 'Frais de rappel', 'total_claim' => 'Total réclamé', 'interest_note' => 'Intérêts moratoires dès le lendemain de l\'échéance de chaque facture, au taux indiqué (art. 104 CO), jusqu\'au paiement.', 'chronology' => 'Chronologie', 'event_invoice' => 'Facture', 'event_due' => 'Échéance de la facture', 'event_settlement' => 'Paiement ou avoir', 'event_notice' => 'Rappel', 'level' => 'niveau', 'attachments' => 'Annexes', 'attachments_text' => 'Les factures, bulletins de versement QR et rappels figurent dans le dossier joint (ZIP).'],
            ],
        ];
    }
}
