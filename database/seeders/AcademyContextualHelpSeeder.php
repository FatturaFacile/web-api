<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\Academy\Models\ContextualHelpArticle;

/**
 * Contextual help (tenant_id null) for the core ERP screens, in it-CH, it-IT, de-CH, fr-CH and en, each with the tooltips
 * of its main fields. Texts are tuples in the order Italian, German, French, English. Idempotent by route and locale.
 */
class AcademyContextualHelpSeeder extends Seeder
{
    public function run(TenantContext $tenantContext): void
    {
        $tenantContext->runAsGlobal(function (): void {
            foreach ($this->screens() as $route => $screen) {
                foreach (['it-CH' => 0, 'it-IT' => 0, 'de-CH' => 1, 'fr-CH' => 2, 'en' => 3] as $locale => $i) {
                    $tooltips = [];

                    foreach ($screen['tips'] as $field => $tip) {
                        $tooltips[$field] = $tip[$i];
                    }

                    ContextualHelpArticle::query()->withoutGlobalScopes()->updateOrCreate(
                        ['tenant_id' => null, 'route_pattern' => $route, 'locale' => $locale],
                        ['title' => $screen['title'][$i], 'body_markdown' => $screen['body'][$i], 'field_tooltips_json' => $tooltips],
                    );
                }
            }
        });
    }

    /**
     * @return array<string, array{title: list<string>, body: list<string>, tips: array<string, list<string>>}>
     */
    private function screens(): array
    {
        return [
            '/dashboard' => [
                'title' => ['Cruscotto', 'Dashboard', 'Tableau de bord', 'Dashboard'],
                'body' => ['Il cruscotto riassume fatturato, incassi aperti e attività del giorno. Usa il filtro periodo per cambiare l\'intervallo.', 'Das Dashboard fasst Umsatz, offene Forderungen und Aufgaben des Tages zusammen. Mit dem Periodenfilter ändern Sie den Zeitraum.', 'Le tableau de bord résume le chiffre d\'affaires, les encaissements ouverts et les tâches du jour. Le filtre de période change l\'intervalle.', 'The dashboard summarises revenue, open receivables and today\'s tasks. Use the period filter to change the range.'],
                'tips' => ['period' => ['Intervallo di date dei dati mostrati.', 'Datumsbereich der angezeigten Daten.', 'Plage de dates des données affichées.', 'Date range of the data shown.']],
            ],
            '/invoices' => [
                'title' => ['Fatture', 'Rechnungen', 'Factures', 'Invoices'],
                'body' => ['Elenco delle fatture di vendita con stato di pagamento e, in Italia, stato SdI. Filtra per cliente, stato o data.', 'Liste der Verkaufsrechnungen mit Zahlungsstatus und, in Italien, SdI-Status. Filtern nach Kunde, Status oder Datum.', 'Liste des factures de vente avec statut de paiement et, en Italie, statut SdI. Filtrez par client, statut ou date.', 'List of sales invoices with payment status and, in Italy, SdI status. Filter by customer, status or date.'],
                'tips' => ['status' => ['Bozza, emessa, pagata o scaduta.', 'Entwurf, ausgestellt, bezahlt oder überfällig.', 'Brouillon, émise, payée ou échue.', 'Draft, issued, paid or overdue.'], 'customer' => ['Filtra per cliente.', 'Nach Kunde filtern.', 'Filtrer par client.', 'Filter by customer.']],
            ],
            '/invoices/new' => [
                'title' => ['Nuova fattura', 'Neue Rechnung', 'Nouvelle facture', 'New invoice'],
                'body' => ['Seleziona il cliente, aggiungi le righe e verifica i totali. In Svizzera la fattura riporta la polizza di versamento con QR; in Italia scegli il tipo documento e la natura IVA.', 'Wählen Sie den Kunden, fügen Sie Positionen hinzu und prüfen Sie die Summen. In der Schweiz enthält die Rechnung den Zahlteil mit QR-Code; in Italien wählen Sie Dokumenttyp und MwSt-Natur.', 'Sélectionnez le client, ajoutez les lignes et vérifiez les totaux. En Suisse la facture comporte le bulletin de versement avec QR; en Italie choisissez le type de document et la nature de TVA.', 'Select the customer, add lines and check the totals. In Switzerland the invoice carries the QR payment slip; in Italy choose the document type and VAT nature.'],
                'tips' => [
                    'contact_id' => ['Il cliente intestatario della fattura.', 'Der Kunde, an den die Rechnung gerichtet ist.', 'Le client destinataire de la facture.', 'The customer the invoice is addressed to.'],
                    'due_date' => ['Data di scadenza del pagamento.', 'Fälligkeitsdatum der Zahlung.', 'Date d\'échéance du paiement.', 'Payment due date.'],
                    'vat_rate' => ['Aliquota IVA della riga; in Italia anche la natura per le operazioni esenti.', 'MwSt-Satz der Position; in Italien auch die Natur bei befreiten Umsätzen.', 'Taux de TVA de la ligne; en Italie aussi la nature pour les opérations exonérées.', 'VAT rate of the line; in Italy also the nature for exempt operations.'],
                ],
            ],
            '/invoices/:id' => [
                'title' => ['Dettaglio fattura', 'Rechnungsdetail', 'Détail de la facture', 'Invoice detail'],
                'body' => ['Righe, totali, pagamenti collegati e storico dello stato. Da qui puoi emettere, inviare, registrare un incasso o creare una nota di credito.', 'Positionen, Summen, verknüpfte Zahlungen und Statusverlauf. Hier können Sie ausstellen, versenden, eine Zahlung erfassen oder eine Gutschrift erstellen.', 'Lignes, totaux, paiements liés et historique du statut. Vous pouvez émettre, envoyer, enregistrer un encaissement ou créer une note de crédit.', 'Lines, totals, linked payments and status history. From here you can issue, send, record a payment or create a credit note.'],
                'tips' => ['total_paid' => ['Somma degli incassi già registrati.', 'Summe der bereits erfassten Zahlungen.', 'Somme des encaissements déjà enregistrés.', 'Sum of the payments already recorded.']],
            ],
            '/contacts' => [
                'title' => ['Contatti', 'Kontakte', 'Contacts', 'Contacts'],
                'body' => ['Anagrafica di clienti, fornitori e altri contatti. Cerca per nome, partita IVA o numero IDI/UID.', 'Stammdaten von Kunden, Lieferanten und weiteren Kontakten. Suche nach Name, MwSt-Nummer oder UID.', 'Fichier des clients, fournisseurs et autres contacts. Recherchez par nom, numéro de TVA ou IDE/UID.', 'Master data of customers, suppliers and other contacts. Search by name, VAT number or UID.'],
                'tips' => ['search' => ['Cerca per nome, email o partita IVA.', 'Suche nach Name, E-Mail oder MwSt-Nummer.', 'Recherche par nom, e-mail ou numéro de TVA.', 'Search by name, email or VAT number.']],
            ],
            '/contacts/new' => [
                'title' => ['Nuovo contatto', 'Neuer Kontakt', 'Nouveau contact', 'New contact'],
                'body' => ['Inserisci i dati anagrafici e fiscali. Per i clienti italiani servono codice fiscale o partita IVA e codice destinatario SdI.', 'Erfassen Sie Stamm- und Steuerdaten. Für italienische Kunden sind Steuernummer oder MwSt-Nummer und der SdI-Empfängercode nötig.', 'Saisissez les données d\'identité et fiscales. Pour les clients italiens il faut le code fiscal ou numéro de TVA et le code destinataire SdI.', 'Enter identity and tax data. Italian customers need a tax code or VAT number and the SdI recipient code.'],
                'tips' => [
                    'vat_number' => ['Partita IVA o numero IVA/UID del contatto.', 'MwSt- oder UID-Nummer des Kontakts.', 'Numéro de TVA ou IDE/UID du contact.', 'VAT or UID number of the contact.'],
                    'sdi_code' => ['Codice destinatario a 7 caratteri per la fatturazione elettronica; 0000000 se si usa la PEC.', 'Siebenstelliger Empfängercode für die E-Rechnung; 0000000 bei Zustellung per PEC.', 'Code destinataire à 7 caractères pour la facturation électronique; 0000000 si la PEC est utilisée.', 'Seven-character recipient code for e-invoicing; 0000000 when delivered by PEC.'],
                ],
            ],
            '/products' => [
                'title' => ['Articoli', 'Artikel', 'Articles', 'Products'],
                'body' => ['Catalogo di prodotti e servizi con prezzi, unità di misura e aliquote IVA predefinite.', 'Katalog von Produkten und Dienstleistungen mit Preisen, Einheiten und Standard-MwSt-Sätzen.', 'Catalogue de produits et services avec prix, unités de mesure et taux de TVA par défaut.', 'Catalogue of products and services with prices, units of measure and default VAT rates.'],
                'tips' => ['sku' => ['Codice univoco dell\'articolo nel tenant.', 'Eindeutiger Artikelcode im Mandanten.', 'Code unique de l\'article dans le tenant.', 'Unique item code within the tenant.']],
            ],
            '/inventory/stock' => [
                'title' => ['Giacenze di magazzino', 'Lagerbestand', 'Stocks', 'Stock levels'],
                'body' => ['Quantità disponibili, riservate e in arrivo per articolo e magazzino. I movimenti aggiornano le giacenze in tempo reale.', 'Verfügbare, reservierte und erwartete Mengen je Artikel und Lager. Bewegungen aktualisieren den Bestand in Echtzeit.', 'Quantités disponibles, réservées et attendues par article et entrepôt. Les mouvements mettent à jour les stocks en temps réel.', 'Available, reserved and incoming quantities per item and warehouse. Movements update stock in real time.'],
                'tips' => ['warehouse_id' => ['Magazzino di cui vedere le giacenze.', 'Lager, dessen Bestand angezeigt wird.', 'Entrepôt dont les stocks sont affichés.', 'Warehouse whose stock is shown.'], 'reserved' => ['Quantità impegnata da ordini non ancora evasi.', 'Durch noch nicht ausgelieferte Aufträge gebundene Menge.', 'Quantité engagée par des commandes non encore livrées.', 'Quantity committed to orders not yet shipped.']],
            ],
            '/purchasing/orders' => [
                'title' => ['Ordini d\'acquisto', 'Einkaufsbestellungen', 'Commandes d\'achat', 'Purchase orders'],
                'body' => ['Ordini ai fornitori, con approvazione e ricevimento merci. Il confronto a tre vie verifica ordine, ricevimento e fattura.', 'Bestellungen bei Lieferanten mit Genehmigung und Wareneingang. Der Drei-Wege-Abgleich prüft Bestellung, Eingang und Rechnung.', 'Commandes aux fournisseurs, avec approbation et réception. Le rapprochement à trois voies contrôle commande, réception et facture.', 'Orders to suppliers, with approval and goods receipt. The three-way match checks order, receipt and invoice.'],
                'tips' => ['supplier_id' => ['Fornitore a cui è indirizzato l\'ordine.', 'Lieferant, an den die Bestellung geht.', 'Fournisseur destinataire de la commande.', 'Supplier the order is sent to.'], 'expected_date' => ['Data di consegna attesa.', 'Erwartetes Lieferdatum.', 'Date de livraison attendue.', 'Expected delivery date.']],
            ],
            '/accounting/journal' => [
                'title' => ['Giornale contabile', 'Journal', 'Journal comptable', 'General journal'],
                'body' => ['Scritture di prima nota in partita doppia. Dare e avere devono quadrare prima della registrazione; una scrittura registrata si storna, non si modifica.', 'Buchungssätze in doppelter Buchhaltung. Soll und Haben müssen vor dem Verbuchen aufgehen; eine verbuchte Buchung wird storniert, nicht geändert.', 'Écritures en partie double. Débit et crédit doivent s\'équilibrer avant la comptabilisation; une écriture comptabilisée se contre-passe, elle ne se modifie pas.', 'Double-entry journal entries. Debits and credits must balance before posting; a posted entry is reversed, not edited.'],
                'tips' => ['account_id' => ['Conto del piano contabile (PMI svizzero o piano italiano).', 'Konto des Kontenplans (Schweizer KMU oder italienischer Plan).', 'Compte du plan comptable (PME suisse ou plan italien).', 'Account of the chart of accounts (Swiss SME or Italian plan).'], 'entry_date' => ['Data di competenza della scrittura.', 'Buchungsdatum der Buchung.', 'Date comptable de l\'écriture.', 'Accounting date of the entry.']],
            ],
            '/hr/employees' => [
                'title' => ['Dipendenti', 'Mitarbeitende', 'Employés', 'Employees'],
                'body' => ['Anagrafica del personale con contratti, reparti e assenze. I dati retributivi sono visibili solo ai ruoli autorizzati.', 'Personalstamm mit Verträgen, Abteilungen und Absenzen. Lohndaten sehen nur berechtigte Rollen.', 'Fichier du personnel avec contrats, départements et absences. Les données salariales ne sont visibles que des rôles autorisés.', 'Staff records with contracts, departments and leave. Pay data is visible to authorised roles only.'],
                'tips' => ['hire_date' => ['Data di assunzione: da qui decorrono ferie e anzianità.', 'Eintrittsdatum: ab hier laufen Ferien und Dienstjahre.', 'Date d\'embauche: les vacances et l\'ancienneté courent à partir d\'ici.', 'Hire date: leave and seniority run from here.']],
            ],
            '/payroll/runs' => [
                'title' => ['Cicli paga', 'Lohnläufe', 'Cycles de paie', 'Payroll runs'],
                'body' => ['Ogni ciclo calcola i cedolini del periodo. Controlla le anteprime, poi finalizza: un ciclo finalizzato genera le scritture contabili e può essere riaperto solo da un amministratore.', 'Jeder Lauf berechnet die Lohnabrechnungen der Periode. Prüfen Sie die Vorschau und schliessen Sie ab: ein abgeschlossener Lauf erzeugt die Buchungen und kann nur von einer Administration wieder geöffnet werden.', 'Chaque cycle calcule les fiches de paie de la période. Contrôlez les aperçus puis finalisez: un cycle finalisé génère les écritures et ne peut être rouvert que par un administrateur.', 'Each run calculates the payslips of the period. Review the previews, then finalise: a finalised run creates the accounting entries and only an administrator can reopen it.'],
                'tips' => ['period' => ['Mese o periodo di paga del ciclo.', 'Lohnmonat oder -periode des Laufs.', 'Mois ou période de paie du cycle.', 'Pay month or period of the run.']],
            ],
            '/treasury/bank' => [
                'title' => ['Banca e riconciliazione', 'Bank und Abgleich', 'Banque et rapprochement', 'Bank and reconciliation'],
                'body' => ['Importa gli estratti conto (CAMT.053, CBI) e abbina i movimenti a fatture e pagamenti. I movimenti non abbinati restano in sospeso.', 'Importieren Sie Kontoauszüge (CAMT.053, CBI) und ordnen Sie Bewegungen Rechnungen und Zahlungen zu. Nicht zugeordnete Bewegungen bleiben offen.', 'Importez les relevés (CAMT.053, CBI) et associez les mouvements aux factures et paiements. Les mouvements non associés restent en suspens.', 'Import bank statements (CAMT.053, CBI) and match movements to invoices and payments. Unmatched movements stay pending.'],
                'tips' => ['statement_file' => ['File dell\'estratto conto in formato CAMT.053 o CBI.', 'Kontoauszugsdatei im Format CAMT.053 oder CBI.', 'Fichier du relevé au format CAMT.053 ou CBI.', 'Statement file in CAMT.053 or CBI format.']],
            ],
        ];
    }
}
