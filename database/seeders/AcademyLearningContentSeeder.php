<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\Academy\Models\AcademyLearningTrack;
use Modules\Academy\Models\InteractiveMission;

/**
 * The shared Academy catalogue (tenant_id null): the five learning tracks and their sandbox missions, with every text in
 * it-CH, it-IT, de-CH, fr-CH and en. A mission is completed by a real ERP domain event raised in a sandbox tenant (the
 * `target_event` of its rule). Idempotent: running it again updates tracks and missions in place by code.
 */
class AcademyLearningContentSeeder extends Seeder
{
    public function run(TenantContext $tenantContext): void
    {
        $tenantContext->runAsGlobal(function (): void {
            foreach ($this->tracks() as $sort => $track) {
                $model = AcademyLearningTrack::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => null, 'code' => $track['code']],
                    ['difficulty' => $track['difficulty'], 'sort_order' => $sort + 1, 'translations_json' => $this->trackTexts($track)],
                );

                foreach ($track['missions'] as $i => $mission) {
                    InteractiveMission::query()->withoutGlobalScopes()->updateOrCreate(
                        ['tenant_id' => null, 'code' => $mission['code']],
                        [
                            'track_id' => $model->id,
                            'difficulty' => $mission['difficulty'] ?? $track['difficulty'],
                            'sequence' => $i + 1,
                            'xp_reward' => $mission['xp'],
                            'badge_code' => $mission['badge'],
                            'verification_rule_json' => ['target_event' => $mission['event'], 'predicate' => $mission['predicate'] ?? '', 'sandbox_only' => true],
                            'tour_steps_json' => [[
                                'route' => $mission['route'],
                                'selector' => $mission['selector'],
                                'placement' => 'bottom',
                                'texts' => $this->locales($mission['step']),
                            ]],
                            'translations_json' => $this->missionTexts($mission),
                        ],
                    );
                }
            }
        });
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $t  Italian, German, French, English.
     * @return array<string, string>
     */
    private function locales(array $t): array
    {
        return ['it-CH' => $t[0], 'it-IT' => $t[0], 'de-CH' => $t[1], 'fr-CH' => $t[2], 'en' => $t[3]];
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, array<string, string>>
     */
    private function trackTexts(array $track): array
    {
        $out = [];

        foreach ($this->locales(['it', 'de', 'fr', 'en']) as $locale => $lang) {
            $i = ['it' => 0, 'de' => 1, 'fr' => 2, 'en' => 3][$lang];
            $out[$locale] = [
                'title' => $track['title'][$i],
                'description' => $track['description'][$i],
                'certificate_heading' => ['Certificato di completamento', 'Abschlusszertifikat', "Certificat d'achèvement", 'Certificate of Completion'][$i],
                'certificate_awarded_to' => ['Rilasciato a', 'Verliehen an', 'Décerné à', 'Awarded to'][$i],
                'certificate_body' => ['ha completato con successo il percorso formativo', 'hat den Lernpfad erfolgreich abgeschlossen', 'a terminé avec succès le parcours de formation', 'has successfully completed the learning track'][$i],
                'certificate_verify_label' => ['Verifica l\'autenticità con il codice QR o all\'indirizzo seguente', 'Echtheit per QR-Code oder unter der folgenden Adresse prüfen', "Vérifiez l'authenticité avec le code QR ou à l'adresse suivante", 'Verify authenticity with the QR code or at the address below'][$i],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $mission
     * @return array<string, array<string, string>>
     */
    private function missionTexts(array $mission): array
    {
        $out = [];

        foreach (['it-CH' => 0, 'it-IT' => 0, 'de-CH' => 1, 'fr-CH' => 2, 'en' => 3] as $locale => $i) {
            $out[$locale] = ['title' => $mission['title'][$i], 'description' => $mission['description'][$i]];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tracks(): array
    {
        return [
            [
                'code' => 'swiss-accounting-kmu', 'difficulty' => 'beginner',
                'title' => ['Contabilità svizzera per PMI', 'Schweizer Buchhaltung für KMU', 'Comptabilité suisse pour PME', 'Swiss accounting for SMEs'],
                'description' => ['Dal primo giornale alla riconciliazione bancaria con il piano contabile svizzero per PMI.', 'Vom ersten Journalbuchungssatz bis zum Bankabgleich mit dem Schweizer KMU-Kontenrahmen.', 'De la première écriture au rapprochement bancaire avec le plan comptable suisse pour PME.', 'From the first journal entry to bank reconciliation with the Swiss SME chart of accounts.'],
                'missions' => [
                    ['code' => 'ch-post-first-journal', 'xp' => 100, 'badge' => 'first-journal', 'event' => 'general_ledger.journal.posted', 'route' => '/general-ledger/journals', 'selector' => '[data-tour="journal-post"]',
                        'title' => ['Registra la prima scrittura', 'Erste Buchung erfassen', 'Comptabiliser la première écriture', 'Post your first journal entry'],
                        'description' => ['Crea e registra una scrittura di giornale nel tenant sandbox.', 'Erstellen und verbuchen Sie einen Journalbuchungssatz im Sandbox-Mandanten.', 'Créez et comptabilisez une écriture de journal dans le tenant sandbox.', 'Create and post a journal entry in the sandbox tenant.'],
                        'step' => ['Premi «Registra» per contabilizzare la scrittura.', 'Klicken Sie auf «Verbuchen», um die Buchung zu erfassen.', 'Cliquez sur «Comptabiliser» pour enregistrer l\'écriture.', 'Press “Post” to book the entry.']],
                    ['code' => 'ch-issue-first-invoice', 'xp' => 100, 'badge' => 'first-invoice', 'event' => 'invoicing.invoice.posted', 'route' => '/sales/invoices', 'selector' => '[data-tour="invoice-post"]',
                        'title' => ['Emetti la prima fattura con QR', 'Erste Rechnung mit QR-Zahlteil', 'Émettre la première facture avec QR', 'Issue your first QR invoice'],
                        'description' => ['Emetti una fattura cliente nel tenant sandbox.', 'Stellen Sie im Sandbox-Mandanten eine Kundenrechnung aus.', 'Émettez une facture client dans le tenant sandbox.', 'Issue a customer invoice in the sandbox tenant.'],
                        'step' => ['Compila il cliente e le righe, poi emetti la fattura.', 'Erfassen Sie Kunde und Positionen und stellen Sie die Rechnung aus.', 'Saisissez le client et les lignes, puis émettez la facture.', 'Fill in the customer and lines, then issue the invoice.']],
                    ['code' => 'ch-reconcile-bank', 'xp' => 150, 'badge' => 'bank-reconciler', 'difficulty' => 'intermediate', 'event' => 'treasury.bank_reconciliation.completed', 'route' => '/treasury', 'selector' => '[data-tour="reconcile"]',
                        'title' => ['Riconcilia un estratto conto', 'Kontoauszug abgleichen', 'Rapprocher un relevé bancaire', 'Reconcile a bank statement'],
                        'description' => ['Importa un estratto conto e completa la riconciliazione.', 'Importieren Sie einen Kontoauszug und schliessen Sie den Abgleich ab.', 'Importez un relevé et terminez le rapprochement.', 'Import a statement and complete the reconciliation.'],
                        'step' => ['Abbina i movimenti alle fatture aperte.', 'Ordnen Sie die Bewegungen den offenen Rechnungen zu.', 'Associez les mouvements aux factures ouvertes.', 'Match the movements to open invoices.']],
                ],
            ],
            [
                'code' => 'italian-fatturapa-and-iva', 'difficulty' => 'intermediate',
                'title' => ['FatturaPA e IVA italiana', 'FatturaPA und italienische MwSt', 'FatturaPA et TVA italienne', 'Italian FatturaPA and VAT'],
                'description' => ['Fatturazione elettronica via SdI, nature IVA e liquidazione periodica.', 'Elektronische Rechnungsstellung über SdI, MwSt-Naturen und periodische Abrechnung.', 'Facturation électronique via SdI, natures de TVA et liquidation périodique.', 'E-invoicing through SdI, VAT natures and periodic VAT settlement.'],
                'missions' => [
                    ['code' => 'it-draft-invoice', 'xp' => 100, 'badge' => 'invoice-drafter', 'difficulty' => 'beginner', 'event' => 'invoicing.invoice.drafted', 'route' => '/sales/invoices', 'selector' => '[data-tour="invoice-save-draft"]',
                        'title' => ['Prepara una bozza di fattura', 'Rechnungsentwurf erstellen', 'Préparer un brouillon de facture', 'Prepare a draft invoice'],
                        'description' => ['Salva una fattura in bozza con il tipo documento TD01.', 'Speichern Sie eine Rechnung als Entwurf mit Dokumenttyp TD01.', 'Enregistrez une facture en brouillon avec le type TD01.', 'Save a draft invoice with document type TD01.'],
                        'step' => ['Scegli il tipo documento e salva la bozza.', 'Wählen Sie den Dokumenttyp und speichern Sie den Entwurf.', 'Choisissez le type de document et enregistrez le brouillon.', 'Choose the document type and save the draft.']],
                    ['code' => 'it-post-invoice', 'xp' => 150, 'badge' => 'sdi-sender', 'event' => 'invoicing.invoice.posted', 'route' => '/sales/invoices', 'selector' => '[data-tour="invoice-send-sdi"]',
                        'title' => ['Emetti e invia al SdI', 'Ausstellen und an SdI senden', 'Émettre et envoyer au SdI', 'Issue and send to SdI'],
                        'description' => ['Emetti la fattura nel sandbox: il canale SdI è simulato.', 'Stellen Sie die Rechnung im Sandbox aus: der SdI-Kanal wird simuliert.', 'Émettez la facture dans le sandbox: le canal SdI est simulé.', 'Issue the invoice in the sandbox: the SdI channel is simulated.'],
                        'step' => ['Emetti la fattura e controlla lo stato SdI.', 'Stellen Sie die Rechnung aus und prüfen Sie den SdI-Status.', 'Émettez la facture et vérifiez le statut SdI.', 'Issue the invoice and check its SdI status.']],
                    ['code' => 'it-close-vat-period', 'xp' => 200, 'badge' => 'vat-closer', 'difficulty' => 'advanced', 'event' => 'general_ledger.period.closed', 'route' => '/general-ledger/close', 'selector' => '[data-tour="period-close"]',
                        'title' => ['Chiudi un periodo IVA', 'MwSt-Periode abschliessen', 'Clôturer une période de TVA', 'Close a VAT period'],
                        'description' => ['Chiudi il periodo contabile dopo la liquidazione IVA.', 'Schliessen Sie die Buchungsperiode nach der MwSt-Abrechnung.', 'Clôturez la période comptable après la liquidation de la TVA.', 'Close the accounting period after the VAT settlement.'],
                        'step' => ['Verifica la liquidazione e chiudi il periodo.', 'Prüfen Sie die Abrechnung und schliessen Sie die Periode.', 'Vérifiez la liquidation et clôturez la période.', 'Check the settlement and close the period.']],
                ],
            ],
            [
                'code' => 'warehouse-and-mrp-mastery', 'difficulty' => 'intermediate',
                'title' => ['Magazzino e MRP', 'Lager und MRP', 'Entrepôt et MRP', 'Warehouse and MRP mastery'],
                'description' => ['Movimenti di magazzino, ricevimento merci e pianificazione dei fabbisogni.', 'Lagerbewegungen, Wareneingang und Materialbedarfsplanung.', 'Mouvements de stock, réception des marchandises et planification des besoins.', 'Stock movements, goods receipt and material requirements planning.'],
                'missions' => [
                    ['code' => 'wh-stock-movement', 'xp' => 100, 'badge' => 'stock-mover', 'difficulty' => 'beginner', 'event' => 'inventory.movement_created', 'route' => '/inventory/stock', 'selector' => '[data-tour="stock-move"]',
                        'title' => ['Registra un movimento di magazzino', 'Lagerbewegung erfassen', 'Enregistrer un mouvement de stock', 'Record a stock movement'],
                        'description' => ['Crea un movimento di carico o scarico nel sandbox.', 'Erstellen Sie im Sandbox eine Zu- oder Abgangsbewegung.', 'Créez un mouvement d\'entrée ou de sortie dans le sandbox.', 'Create a receipt or issue movement in the sandbox.'],
                        'step' => ['Scegli articolo, magazzino e quantità.', 'Wählen Sie Artikel, Lager und Menge.', 'Choisissez l\'article, l\'entrepôt et la quantité.', 'Choose the item, warehouse and quantity.']],
                    ['code' => 'wh-goods-receipt', 'xp' => 150, 'badge' => 'receiver', 'event' => 'procurement.goods_received', 'route' => '/purchases/orders', 'selector' => '[data-tour="goods-receipt"]',
                        'title' => ['Ricevi la merce di un ordine', 'Ware einer Bestellung annehmen', 'Réceptionner les marchandises d\'une commande', 'Receive the goods of an order'],
                        'description' => ['Registra il ricevimento merci di un ordine d\'acquisto.', 'Erfassen Sie den Wareneingang einer Bestellung.', 'Enregistrez la réception des marchandises d\'une commande d\'achat.', 'Record the goods receipt of a purchase order.'],
                        'step' => ['Apri l\'ordine e conferma le quantità ricevute.', 'Öffnen Sie die Bestellung und bestätigen Sie die erhaltenen Mengen.', 'Ouvrez la commande et confirmez les quantités reçues.', 'Open the order and confirm the received quantities.']],
                    ['code' => 'wh-run-mrp', 'xp' => 250, 'badge' => 'mrp-planner', 'difficulty' => 'advanced', 'event' => 'mfg.mrp.completed', 'route' => '/manufacturing/mrp', 'selector' => '[data-tour="mrp-run"]',
                        'title' => ['Esegui il calcolo MRP', 'MRP-Lauf ausführen', 'Lancer le calcul MRP', 'Run the MRP calculation'],
                        'description' => ['Avvia una pianificazione MRP e controlla le proposte.', 'Starten Sie eine MRP-Planung und prüfen Sie die Vorschläge.', 'Lancez une planification MRP et contrôlez les propositions.', 'Start an MRP run and review the proposals.'],
                        'step' => ['Avvia il calcolo e apri le proposte generate.', 'Starten Sie den Lauf und öffnen Sie die Vorschläge.', 'Lancez le calcul et ouvrez les propositions.', 'Start the run and open the proposals.']],
                ],
            ],
            [
                'code' => 'hr-and-payroll-ch-it', 'difficulty' => 'intermediate',
                'title' => ['HR e paghe CH/IT', 'HR und Lohn CH/IT', 'RH et paie CH/IT', 'HR and payroll CH/IT'],
                'description' => ['Assunzioni, assenze e chiusura del ciclo paga in Svizzera e in Italia.', 'Anstellungen, Absenzen und Lohnlauf in der Schweiz und in Italien.', 'Embauches, absences et clôture de la paie en Suisse et en Italie.', 'Hiring, leave and the payroll run in Switzerland and Italy.'],
                'missions' => [
                    ['code' => 'hr-hire-employee', 'xp' => 100, 'badge' => 'hr-starter', 'difficulty' => 'beginner', 'event' => 'hr.employee.hired', 'route' => '/hr/employees', 'selector' => '[data-tour="employee-hire"]',
                        'title' => ['Assumi un dipendente', 'Mitarbeitenden anstellen', 'Embaucher un employé', 'Hire an employee'],
                        'description' => ['Registra l\'assunzione di un dipendente nel sandbox.', 'Erfassen Sie die Anstellung einer Person im Sandbox.', 'Enregistrez l\'embauche d\'un employé dans le sandbox.', 'Record the hiring of an employee in the sandbox.'],
                        'step' => ['Inserisci i dati contrattuali e conferma.', 'Erfassen Sie die Vertragsdaten und bestätigen Sie.', 'Saisissez les données contractuelles et confirmez.', 'Enter the contract data and confirm.']],
                    ['code' => 'hr-approve-leave', 'xp' => 100, 'badge' => 'leave-approver', 'event' => 'hr.leave.approved', 'route' => '/hr/employees', 'selector' => '[data-tour="leave-approve"]',
                        'title' => ['Approva una richiesta di assenza', 'Absenzgesuch genehmigen', 'Approuver une demande d\'absence', 'Approve a leave request'],
                        'description' => ['Approva una richiesta di ferie o assenza.', 'Genehmigen Sie ein Ferien- oder Absenzgesuch.', 'Approuvez une demande de vacances ou d\'absence.', 'Approve a vacation or absence request.'],
                        'step' => ['Apri la richiesta e premi «Approva».', 'Öffnen Sie das Gesuch und klicken Sie auf «Genehmigen».', 'Ouvrez la demande et cliquez sur «Approuver».', 'Open the request and press “Approve”.']],
                    ['code' => 'hr-finalize-payroll', 'xp' => 250, 'badge' => 'payroll-pro', 'difficulty' => 'advanced', 'event' => 'accounting.payroll.run_finalized', 'route' => '/hr/payroll', 'selector' => '[data-tour="payroll-finalize"]',
                        'title' => ['Finalizza un ciclo paga', 'Lohnlauf abschliessen', 'Finaliser un cycle de paie', 'Finalise a payroll run'],
                        'description' => ['Calcola e finalizza un ciclo paga nel sandbox.', 'Berechnen und schliessen Sie einen Lohnlauf im Sandbox ab.', 'Calculez et finalisez un cycle de paie dans le sandbox.', 'Calculate and finalise a payroll run in the sandbox.'],
                        'step' => ['Controlla i cedolini e finalizza il ciclo.', 'Prüfen Sie die Lohnabrechnungen und schliessen Sie den Lauf ab.', 'Contrôlez les fiches de paie et finalisez le cycle.', 'Review the payslips and finalise the run.']],
                ],
            ],
            [
                'code' => 'erp-developer-architecture', 'difficulty' => 'advanced',
                'title' => ['Architettura ERP per sviluppatori', 'ERP-Architektur für Entwickler', 'Architecture ERP pour développeurs', 'ERP developer architecture'],
                'description' => ['Il flusso ordine-acquisto dal punto di vista degli eventi di dominio.', 'Der Auftrags-Einkaufs-Fluss aus Sicht der Domänenereignisse.', 'Le flux commande-achat du point de vue des événements de domaine.', 'The order-to-purchase flow seen through domain events.'],
                'missions' => [
                    ['code' => 'dev-create-sales-order', 'xp' => 100, 'badge' => 'event-watcher', 'difficulty' => 'intermediate', 'event' => 'sales.order.created', 'route' => '/sales/orders', 'selector' => '[data-tour="order-create"]',
                        'title' => ['Crea un ordine cliente', 'Kundenauftrag erstellen', 'Créer une commande client', 'Create a sales order'],
                        'description' => ['Crea un ordine e osserva l\'evento di dominio sales.order.created.', 'Erstellen Sie einen Auftrag und beobachten Sie das Domänenereignis sales.order.created.', 'Créez une commande et observez l\'événement de domaine sales.order.created.', 'Create an order and watch the sales.order.created domain event.'],
                        'step' => ['Salva l\'ordine: l\'evento viene pubblicato sul bus.', 'Speichern Sie den Auftrag: das Ereignis wird auf dem Bus publiziert.', 'Enregistrez la commande: l\'événement est publié sur le bus.', 'Save the order: the event is published on the bus.']],
                    ['code' => 'dev-approve-sales-order', 'xp' => 150, 'badge' => 'workflow-builder', 'event' => 'sales.order.approved', 'route' => '/sales/orders', 'selector' => '[data-tour="order-approve"]',
                        'title' => ['Approva l\'ordine', 'Auftrag genehmigen', 'Approuver la commande', 'Approve the order'],
                        'description' => ['Fai avanzare il workflow di approvazione dell\'ordine.', 'Führen Sie den Genehmigungsworkflow des Auftrags weiter.', 'Faites avancer le workflow d\'approbation de la commande.', 'Move the order approval workflow forward.'],
                        'step' => ['Approva l\'ordine e segui le reazioni a valle.', 'Genehmigen Sie den Auftrag und verfolgen Sie die Folgereaktionen.', 'Approuvez la commande et suivez les réactions en aval.', 'Approve the order and follow the downstream reactions.']],
                    ['code' => 'dev-approve-purchase-order', 'xp' => 200, 'badge' => 'integrator', 'difficulty' => 'advanced', 'event' => 'procurement.purchase_order.approved', 'route' => '/purchases/orders', 'selector' => '[data-tour="po-approve"]',
                        'title' => ['Approva un ordine d\'acquisto', 'Bestellung genehmigen', 'Approuver une commande d\'achat', 'Approve a purchase order'],
                        'description' => ['Chiudi il cerchio: approva un ordine d\'acquisto in un altro modulo.', 'Schliessen Sie den Kreis: genehmigen Sie eine Bestellung in einem anderen Modul.', 'Bouclez la boucle: approuvez une commande d\'achat dans un autre module.', 'Close the loop: approve a purchase order in another module.'],
                        'step' => ['Approva l\'ordine d\'acquisto per completare il percorso.', 'Genehmigen Sie die Bestellung, um den Pfad abzuschliessen.', 'Approuvez la commande d\'achat pour terminer le parcours.', 'Approve the purchase order to finish the track.']],
                ],
            ],
        ];
    }
}
