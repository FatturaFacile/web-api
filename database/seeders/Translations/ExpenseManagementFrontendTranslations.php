<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the expense and travel management module (Mod 075): expense reports and lines, mileage claims (ACI and
 * TCS rates), per diems (Art. 51 TUIR), the receipt OCR workbench, the corporate card reconciliation, the policy
 * violations and the codes the API reports (expenseManagement.error.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, TUIR, ACI, TCS, IRPEF) stay untranslated.
 */
final class ExpenseManagementFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::violations(),
            self::fiscalNotices(),
            self::errors(),
            self::audit(),
            self::labels(),
            self::actions(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.EXPENSE_MANAGEMENT' => ['Note spese e trasferte', 'Expenses and travel', 'Spesen und Reisen', 'Notes de frais et déplacements'],
            'NAV.EXPENSE_MANAGEMENT_REPORTS' => ['Note spese', 'Expense reports', 'Spesenabrechnungen', 'Notes de frais'],
            'NAV.EXPENSE_MANAGEMENT_REPORT' => ['Nota spese', 'Expense report', 'Spesenabrechnung', 'Note de frais'],
            'NAV.EXPENSE_MANAGEMENT_WORKBENCH' => ['Scansione ricevute', 'Receipt scanning', 'Belegerfassung', 'Numérisation des reçus'],
            'NAV.EXPENSE_MANAGEMENT_CARDS' => ['Carte aziendali', 'Corporate cards', 'Firmenkarten', 'Cartes d’entreprise'],
            'NAV.EXPENSE_MANAGEMENT_VIOLATIONS' => ['Violazioni di policy', 'Policy violations', 'Richtlinienverstösse', 'Violations de politique'],
            'PAGE_TITLE.EXPENSE_MANAGEMENT_REPORTS' => ['Note spese e trasferte', 'Expense and travel reports', 'Spesen- und Reiseabrechnungen', 'Notes de frais et déplacements'],
            'PAGE_TITLE.EXPENSE_MANAGEMENT_REPORT' => ['Dettaglio nota spese', 'Expense report detail', 'Details der Spesenabrechnung', 'Détail de la note de frais'],
            'PAGE_TITLE.EXPENSE_MANAGEMENT_WORKBENCH' => ['Scansione e verifica ricevute', 'Receipt scanning and review', 'Belegerfassung und Prüfung', 'Numérisation et contrôle des reçus'],
            'PAGE_TITLE.EXPENSE_MANAGEMENT_CARDS' => ['Riconciliazione carte aziendali', 'Corporate card reconciliation', 'Abstimmung der Firmenkarten', 'Rapprochement des cartes d’entreprise'],
            'PAGE_TITLE.EXPENSE_MANAGEMENT_VIOLATIONS' => ['Violazioni delle policy di spesa', 'Expense policy violations', 'Verstösse gegen die Spesenrichtlinie', 'Violations de la politique de dépenses'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'expenseManagement.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'expenseManagement.status.submitted' => ['Inviata', 'Submitted', 'Eingereicht', 'Soumise'],
            'expenseManagement.status.under_review' => ['In revisione', 'Under review', 'In Prüfung', 'En révision'],
            'expenseManagement.status.approved' => ['Approvata', 'Approved', 'Genehmigt', 'Approuvée'],
            'expenseManagement.status.rejected' => ['Respinta', 'Rejected', 'Abgelehnt', 'Refusée'],
            'expenseManagement.status.posted' => ['Contabilizzata', 'Posted', 'Verbucht', 'Comptabilisée'],
            'expenseManagement.status.reimbursed' => ['Rimborsata', 'Reimbursed', 'Erstattet', 'Remboursée'],
            'expenseManagement.status.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'expenseManagement.paymentMethod.employee_out_of_pocket' => ['Anticipata dal dipendente', 'Paid by the employee', 'Vom Mitarbeiter vorgestreckt', 'Avancée par le collaborateur'],
            'expenseManagement.paymentMethod.corporate_card' => ['Carta aziendale', 'Corporate card', 'Firmenkarte', 'Carte d’entreprise'],
            'expenseManagement.calculationSource.CH_TCS' => ['Tariffa TCS (Svizzera)', 'TCS rate (Switzerland)', 'TCS-Ansatz (Schweiz)', 'Tarif TCS (Suisse)'],
            'expenseManagement.calculationSource.CH_SPESENREGLEMENT' => ['Regolamento spese aziendale (Svizzera)', 'Company expense regulation (Switzerland)', 'Spesenreglement des Unternehmens (Schweiz)', 'Règlement de frais de l’entreprise (Suisse)'],
            'expenseManagement.calculationSource.IT_ACI_TABLE' => ['Tabelle ACI (Italia)', 'ACI tables (Italy)', 'ACI-Tabellen (Italien)', 'Tableaux ACI (Italie)'],
            'expenseManagement.destinationType.municipality' => ['Nel comune della sede', 'Within the home municipality', 'Innerhalb der Wohngemeinde', 'Dans la commune du siège'],
            'expenseManagement.destinationType.domestic' => ['Territorio nazionale', 'Domestic', 'Inland', 'Territoire national'],
            'expenseManagement.destinationType.international' => ['Estero', 'International', 'Ausland', 'Étranger'],
            'expenseManagement.allowanceMethod.flat_rate' => ['Forfettario', 'Flat rate', 'Pauschal', 'Forfaitaire'],
            'expenseManagement.allowanceMethod.mixed' => ['Misto', 'Mixed', 'Gemischt', 'Mixte'],
            'expenseManagement.allowanceMethod.analytic' => ['Analitico (a piè di lista)', 'Analytic (itemised)', 'Analytisch (nach Beleg)', 'Analytique (sur justificatifs)'],
            'expenseManagement.reconciliationStatus.unmatched' => ['Da riconciliare', 'Unmatched', 'Offen', 'Non rapprochée'],
            'expenseManagement.reconciliationStatus.matched' => ['Riconciliata', 'Matched', 'Abgestimmt', 'Rapprochée'],
            'expenseManagement.reconciliationStatus.ignored' => ['Ignorata', 'Ignored', 'Ignoriert', 'Ignorée'],
            'expenseManagement.reconciliationStatus.disputed' => ['Contestata', 'Disputed', 'Beanstandet', 'Contestée'],
            'expenseManagement.severity.warning' => ['Avviso', 'Warning', 'Warnung', 'Avertissement'],
            'expenseManagement.severity.blocking' => ['Bloccante', 'Blocking', 'Blockierend', 'Bloquante'],
            'expenseManagement.channel.pain001' => ['Bonifico (pain.001)', 'Bank transfer (pain.001)', 'Überweisung (pain.001)', 'Virement (pain.001)'],
            'expenseManagement.channel.payroll' => ['Cedolino paga', 'Payroll', 'Lohnabrechnung', 'Fiche de paie'],
            'expenseManagement.category.meals' => ['Pasti', 'Meals', 'Verpflegung', 'Repas'],
            'expenseManagement.category.lodging' => ['Alloggio', 'Lodging', 'Unterkunft', 'Hébergement'],
            'expenseManagement.category.local_transport' => ['Trasporti locali e taxi', 'Local transport and taxi', 'Lokaler Verkehr und Taxi', 'Transports locaux et taxi'],
            'expenseManagement.category.fuel' => ['Carburante', 'Fuel', 'Treibstoff', 'Carburant'],
            'expenseManagement.category.mileage' => ['Rimborso chilometrico', 'Mileage', 'Kilometerentschädigung', 'Indemnité kilométrique'],
            'expenseManagement.category.tolls_parking' => ['Pedaggi e parcheggi', 'Tolls and parking', 'Maut und Parkgebühren', 'Péages et stationnement'],
            'expenseManagement.category.supplies' => ['Materiale e forniture', 'Supplies', 'Material und Bedarf', 'Fournitures'],
            'expenseManagement.category.representation' => ['Spese di rappresentanza', 'Representation', 'Repräsentationsspesen', 'Frais de représentation'],
            'expenseManagement.category.other' => ['Altre spese', 'Other expenses', 'Übrige Spesen', 'Autres frais'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function violations(): array
    {
        return [
            'expenseManagement.violation.duplicate_receipt_hash' => ['Ricevuta già caricata (stesso file)', 'Receipt already uploaded (same file)', 'Beleg bereits hochgeladen (gleiche Datei)', 'Reçu déjà téléversé (même fichier)'],
            'expenseManagement.violation.duplicate_merchant_date_amount' => ['Possibile duplicato: stesso esercente, data e importo', 'Possible duplicate: same merchant, date and amount', 'Mögliches Duplikat: gleicher Händler, Datum und Betrag', 'Doublon possible : même commerçant, date et montant'],
            'expenseManagement.violation.exceeds_item_cap' => ['Supera il massimale per singola spesa', 'Exceeds the per-item cap', 'Überschreitet das Limit pro Beleg', 'Dépasse le plafond par dépense'],
            'expenseManagement.violation.exceeds_daily_cap' => ['Supera il massimale giornaliero', 'Exceeds the daily cap', 'Überschreitet das Tageslimit', 'Dépasse le plafond journalier'],
            'expenseManagement.violation.receipt_required' => ['Ricevuta obbligatoria', 'Receipt required', 'Beleg erforderlich', 'Reçu obligatoire'],
            'expenseManagement.violation.non_traceable_payment' => ['Pagamento non tracciabile: l’importo è imponibile e non deducibile', 'Non-traceable payment: the amount is taxable and not deductible', 'Nicht nachverfolgbare Zahlung: der Betrag ist steuerpflichtig und nicht abzugsfähig', 'Paiement non traçable : le montant est imposable et non déductible'],
            'expenseManagement.violation.taxable_per_diem_excess' => ['Eccedenza della diaria imponibile', 'Taxable per diem excess', 'Steuerpflichtiger Überschuss der Tagespauschale', 'Excédent imposable de l’indemnité journalière'],
            'expenseManagement.violation.missing_merchant_tax_id' => ['Partita IVA o UID dell’esercente mancante: IVA non recuperabile', 'Merchant P/IVA or UID missing: VAT is not recoverable', 'P/IVA oder UID des Händlers fehlt: MWST nicht rückforderbar', 'P/IVA ou IDE du commerçant manquant : TVA non récupérable'],
            'expenseManagement.violation.outside_report_period' => ['Data fuori dal periodo della nota spese', 'Date outside the report period', 'Datum ausserhalb des Abrechnungszeitraums', 'Date hors de la période de la note de frais'],
            'expenseManagement.violation.future_date' => ['Data futura', 'Date in the future', 'Datum in der Zukunft', 'Date dans le futur'],
            'expenseManagement.violation.expense_too_old' => ['Spesa troppo datata per il rimborso', 'Expense too old to be reimbursed', 'Spese zu alt für eine Erstattung', 'Dépense trop ancienne pour être remboursée'],
            'expenseManagement.violation.low_ocr_confidence' => ['Lettura OCR poco affidabile: verifica tutti i campi', 'Low OCR confidence: check every field', 'Geringe OCR-Sicherheit: alle Felder prüfen', 'Lecture OCR peu fiable : vérifiez tous les champs'],
            'expenseManagement.violation.vat_not_recoverable' => ['IVA non recuperabile', 'VAT not recoverable', 'MWST nicht rückforderbar', 'TVA non récupérable'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fiscalNotices(): array
    {
        return [
            'expenseManagement.fiscal.it_art51_tuir' => [
                'Art. 51, comma 5 TUIR: il rimborso analitico (a piè di lista) di vitto, alloggio e viaggio documentati non concorre al reddito del dipendente; l’indennità di trasferta forfettaria o mista è esente solo entro i limiti giornalieri, l’eccedenza è soggetta a IRPEF e contributi.',
                'Art. 51(5) TUIR: documented itemised reimbursement of meals, lodging and travel is not part of the employee’s taxable income; a flat-rate or mixed transfer allowance is exempt only within the daily limits, the excess is subject to IRPEF and social contributions.',
                'Art. 51 Abs. 5 TUIR: die belegte Einzelabrechnung von Verpflegung, Unterkunft und Reise gehört nicht zum steuerpflichtigen Einkommen; eine pauschale oder gemischte Reiseentschädigung ist nur innerhalb der Tageslimiten steuerfrei, der Überschuss unterliegt IRPEF und Sozialbeiträgen.',
                'Art. 51, al. 5 TUIR : le remboursement analytique justifié des repas, de l’hébergement et du voyage n’entre pas dans le revenu imposable ; l’indemnité forfaitaire ou mixte n’est exonérée que dans les limites journalières, l’excédent est soumis à l’IRPEF et aux cotisations.',
            ],
            'expenseManagement.fiscal.it_traceable_payment' => [
                'Pagamento tracciabile obbligatorio: vitto, alloggio e taxi pagati in contanti non sono deducibili, l’IVA non è detraibile e il rimborso è imponibile per il dipendente. Usa carta, bonifico o altro mezzo tracciabile.',
                'Traceable payment required: meals, lodging and taxi paid in cash are not deductible, VAT is not recoverable and the reimbursement is taxable for the employee. Use a card, a bank transfer or another traceable means.',
                'Nachverfolgbare Zahlung erforderlich: bar bezahlte Verpflegung, Unterkunft und Taxi sind nicht abzugsfähig, die MWST ist nicht rückforderbar und die Erstattung ist für den Mitarbeiter steuerpflichtig. Karte, Überweisung oder ein anderes nachverfolgbares Mittel verwenden.',
                'Paiement traçable obligatoire : repas, hébergement et taxi payés en espèces ne sont pas déductibles, la TVA n’est pas récupérable et le remboursement est imposable pour le collaborateur. Utilisez une carte, un virement ou un autre moyen traçable.',
            ],
            'expenseManagement.fiscal.ch_spesenreglement' => [
                'Regolamento spese approvato dal cantone: rimborsi forfettari o a consuntivo secondo il regolamento aziendale; le spese effettive sono documentate, le forfettarie rientrano nei limiti approvati.',
                'Canton-approved expense regulation: flat-rate or itemised reimbursements follow the company regulation; actual expenses are documented, flat rates stay within the approved limits.',
                'Vom Kanton genehmigtes Spesenreglement: Pauschal- oder Effektivspesen richten sich nach dem Reglement des Unternehmens; Effektivspesen sind belegt, Pauschalen bleiben innerhalb der genehmigten Ansätze.',
                'Règlement de frais approuvé par le canton : les remboursements forfaitaires ou effectifs suivent le règlement de l’entreprise ; les frais effectifs sont justifiés, les forfaits restent dans les limites approuvées.',
            ],
            'expenseManagement.fiscal.ch_tcs_mileage' => [
                'Indennità chilometrica: tariffa standard di CHF 0.70/km (configurabile nel regolamento) o tariffa TCS del veicolo. La MWST è recuperabile sulle ricevute commerciali qualificate.',
                'Mileage allowance: standard rate of CHF 0.70/km (configurable in the regulation) or the TCS rate of the vehicle. MWST is recoverable on qualifying commercial receipts.',
                'Kilometerentschädigung: Standardansatz von CHF 0.70/km (im Reglement konfigurierbar) oder TCS-Ansatz des Fahrzeugs. Die MWST ist auf qualifizierte Geschäftsbelege rückforderbar.',
                'Indemnité kilométrique : tarif standard de CHF 0.70/km (configurable dans le règlement) ou tarif TCS du véhicule. La TVA est récupérable sur les justificatifs commerciaux qualifiés.',
            ],
            'expenseManagement.fiscal.it_aci_tables' => [
                'Rimborso chilometrico calcolato con le tabelle ACI semestrali dei costi chilometrici, per veicolo e fascia di percorrenza annua.',
                'Mileage reimbursement calculated with the semi-annual ACI cost-per-km tables, by vehicle and annual mileage bracket.',
                'Kilometerentschädigung nach den halbjährlichen ACI-Kilometerkostentabellen, je Fahrzeug und jährlicher Fahrleistung.',
                'Remboursement kilométrique calculé avec les tableaux semestriels ACI des coûts au kilomètre, par véhicule et tranche de kilométrage annuel.',
            ],
            'expenseManagement.fiscal.it_per_diem_limits' => [
                'Limiti giornalieri esenti (Art. 51 c. 5 TUIR): € 46,48 in Italia e € 77,47 all’estero; il limite si riduce di un terzo se vitto o alloggio sono forniti o rimborsati analiticamente, di due terzi se lo sono entrambi. Con rimborso analitico l’indennità per spese non documentabili è esente fino a € 15,49 in Italia e € 25,82 all’estero. Nel comune della sede l’indennità è sempre imponibile.',
                'Tax-free daily limits (Art. 51(5) TUIR): € 46.48 in Italy and € 77.47 abroad; the limit is reduced by one third if meals or lodging are provided or reimbursed itemised, by two thirds if both are. With itemised reimbursement the allowance for non-documentable expenses is exempt up to € 15.49 in Italy and € 25.82 abroad. Within the home municipality the allowance is always taxable.',
                'Steuerfreie Tageslimiten (Art. 51 Abs. 5 TUIR): € 46,48 in Italien und € 77,47 im Ausland; das Limit sinkt um ein Drittel, wenn Verpflegung oder Unterkunft gestellt oder einzeln erstattet werden, um zwei Drittel, wenn beides. Bei Einzelabrechnung ist die Entschädigung für nicht belegbare Auslagen bis € 15,49 in Italien und € 25,82 im Ausland steuerfrei. In der Wohngemeinde ist die Entschädigung stets steuerpflichtig.',
                'Plafonds journaliers exonérés (art. 51, al. 5 TUIR) : 46,48 € en Italie et 77,47 € à l’étranger ; le plafond est réduit d’un tiers si les repas ou l’hébergement sont fournis ou remboursés analytiquement, de deux tiers si les deux le sont. Avec remboursement analytique, l’indemnité pour frais non justifiables est exonérée jusqu’à 15,49 € en Italie et 25,82 € à l’étranger. Dans la commune du siège, l’indemnité est toujours imposable.',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'expenseManagement.error.generic' => ['Operazione non riuscita.', 'The operation failed.', 'Der Vorgang ist fehlgeschlagen.', 'L’opération a échoué.'],
            'expenseManagement.error.report_not_editable' => ['La nota spese non è modificabile nel suo stato attuale.', 'The expense report cannot be edited in its current status.', 'Die Spesenabrechnung kann im aktuellen Status nicht bearbeitet werden.', 'La note de frais ne peut pas être modifiée dans son état actuel.'],
            'expenseManagement.error.report_empty' => ['La nota spese non contiene spese né diarie.', 'The expense report has no expenses or per diems.', 'Die Spesenabrechnung enthält weder Spesen noch Tagespauschalen.', 'La note de frais ne contient ni dépenses ni indemnités.'],
            'expenseManagement.error.blocking_violations' => ['Ci sono violazioni bloccanti: correggile prima di inviare la nota spese.', 'There are blocking violations: fix them before submitting the report.', 'Es gibt blockierende Verstösse: vor dem Einreichen beheben.', 'Des violations bloquantes existent : corrigez-les avant de soumettre la note.'],
            'expenseManagement.error.missing_receipt' => ['Manca la ricevuta obbligatoria.', 'A mandatory receipt is missing.', 'Ein erforderlicher Beleg fehlt.', 'Un reçu obligatoire est manquant.'],
            'expenseManagement.error.invalid_transition' => ['Questo passaggio di stato non è consentito.', 'This status change is not allowed.', 'Dieser Statuswechsel ist nicht erlaubt.', 'Ce changement de statut n’est pas autorisé.'],
            'expenseManagement.error.self_approval_forbidden' => ['Non puoi approvare la tua stessa nota spese.', 'You cannot approve your own expense report.', 'Die eigene Spesenabrechnung kann nicht genehmigt werden.', 'Vous ne pouvez pas approuver votre propre note de frais.'],
            'expenseManagement.error.rejection_reason_required' => ['Indica il motivo del rifiuto.', 'Give the reason for the rejection.', 'Den Ablehnungsgrund angeben.', 'Indiquez le motif du refus.'],
            'expenseManagement.error.no_reimbursable_amount' => ['Non c’è alcun importo da rimborsare.', 'There is no amount to reimburse.', 'Es gibt keinen Betrag zu erstatten.', 'Aucun montant à rembourser.'],
            'expenseManagement.error.employee_iban_missing' => ['Manca l’IBAN del dipendente per il bonifico.', 'The employee’s IBAN is missing for the transfer.', 'Die IBAN des Mitarbeiters für die Überweisung fehlt.', 'L’IBAN du collaborateur est manquant pour le virement.'],
            'expenseManagement.error.card_transaction_mismatch' => ['La spesa non corrisponde alla transazione della carta.', 'The expense does not match the card transaction.', 'Die Spese passt nicht zur Kartentransaktion.', 'La dépense ne correspond pas à la transaction de la carte.'],
            'expenseManagement.error.card_transaction_already_matched' => ['La transazione è già riconciliata.', 'The transaction is already matched.', 'Die Transaktion ist bereits abgestimmt.', 'La transaction est déjà rapprochée.'],
            'expenseManagement.error.vehicle_rate_not_found' => ['Nessuna tariffa chilometrica per il veicolo e la data indicati.', 'No mileage rate for the vehicle and date given.', 'Kein Kilometeransatz für das angegebene Fahrzeug und Datum.', 'Aucun tarif kilométrique pour le véhicule et la date indiqués.'],
            'expenseManagement.error.currency_rate_missing' => ['Manca il tasso di cambio per la valuta della spesa.', 'The exchange rate for the expense currency is missing.', 'Der Wechselkurs für die Spesenwährung fehlt.', 'Le taux de change de la devise de la dépense est manquant.'],
            'expenseManagement.error.receipt_not_found' => ['Ricevuta non trovata.', 'Receipt not found.', 'Beleg nicht gefunden.', 'Reçu introuvable.'],
            'expenseManagement.error.period_invalid' => ['Il periodo indicato non è valido.', 'The period given is not valid.', 'Der angegebene Zeitraum ist ungültig.', 'La période indiquée n’est pas valide.'],
            'expenseManagement.error.category_not_found' => ['Categoria di spesa non trovata.', 'Expense category not found.', 'Spesenkategorie nicht gefunden.', 'Catégorie de dépense introuvable.'],
            'expenseManagement.error.amount_exceeds_transaction' => ['Gli importi abbinati superano la transazione.', 'The matched amounts exceed the transaction.', 'Die zugeordneten Beträge übersteigen die Transaktion.', 'Les montants rapprochés dépassent la transaction.'],
            'expenseManagement.error.debtor_account_missing' => ['Manca il conto di addebito dell’azienda per il bonifico.', 'The company debtor account for the transfer is missing.', 'Das Belastungskonto des Unternehmens für die Überweisung fehlt.', 'Le compte débiteur de l’entreprise pour le virement est manquant.'],
            'expenseManagement.error.approved_amount_exceeds' => ['L’importo approvato supera il totale rimborsabile.', 'The approved amount exceeds the reimbursable total.', 'Der genehmigte Betrag übersteigt den erstattungsfähigen Gesamtbetrag.', 'Le montant approuvé dépasse le total remboursable.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function audit(): array
    {
        return [
            'expenseManagement.audit.created' => ['Nota spese creata', 'Report created', 'Abrechnung erstellt', 'Note de frais créée'],
            'expenseManagement.audit.updated' => ['Nota spese modificata', 'Report updated', 'Abrechnung geändert', 'Note de frais modifiée'],
            'expenseManagement.audit.line_added' => ['Spesa aggiunta', 'Expense added', 'Spese hinzugefügt', 'Dépense ajoutée'],
            'expenseManagement.audit.line_updated' => ['Spesa modificata', 'Expense updated', 'Spese geändert', 'Dépense modifiée'],
            'expenseManagement.audit.line_removed' => ['Spesa rimossa', 'Expense removed', 'Spese entfernt', 'Dépense supprimée'],
            'expenseManagement.audit.mileage_added' => ['Rimborso chilometrico aggiunto', 'Mileage claim added', 'Kilometerentschädigung hinzugefügt', 'Indemnité kilométrique ajoutée'],
            'expenseManagement.audit.per_diem_added' => ['Diaria aggiunta', 'Per diem added', 'Tagespauschale hinzugefügt', 'Indemnité journalière ajoutée'],
            'expenseManagement.audit.per_diem_removed' => ['Diaria rimossa', 'Per diem removed', 'Tagespauschale entfernt', 'Indemnité journalière supprimée'],
            'expenseManagement.audit.review_started' => ['Revisione avviata', 'Review started', 'Prüfung gestartet', 'Révision démarrée'],
            'expenseManagement.audit.card_matched' => ['Transazione della carta abbinata', 'Card transaction matched', 'Kartentransaktion zugeordnet', 'Transaction de carte associée'],
            'expenseManagement.audit.card_unmatched' => ['Abbinamento della carta annullato', 'Card match removed', 'Kartenzuordnung aufgehoben', 'Association de carte supprimée'],
            'expenseManagement.audit.submitted' => ['Inviata per approvazione', 'Submitted for approval', 'Zur Genehmigung eingereicht', 'Soumise pour approbation'],
            'expenseManagement.audit.approved' => ['Approvata', 'Approved', 'Genehmigt', 'Approuvée'],
            'expenseManagement.audit.rejected' => ['Respinta', 'Rejected', 'Abgelehnt', 'Refusée'],
            'expenseManagement.audit.payout_exported' => ['Pagamento esportato', 'Payout exported', 'Auszahlung exportiert', 'Paiement exporté'],
            'expenseManagement.audit.reimbursed' => ['Rimborso confermato', 'Reimbursement confirmed', 'Erstattung bestätigt', 'Remboursement confirmé'],
            'expenseManagement.audit.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function labels(): array
    {
        return [
            'expenseManagement.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'expenseManagement.field.employee' => ['Dipendente', 'Employee', 'Mitarbeiter', 'Collaborateur'],
            'expenseManagement.field.from' => ['Dal', 'From', 'Von', 'Du'],
            'expenseManagement.field.to' => ['Al', 'To', 'Bis', 'Au'],
            'expenseManagement.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'expenseManagement.field.number' => ['Numero', 'Number', 'Nummer', 'Numéro'],
            'expenseManagement.field.title' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'expenseManagement.field.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'expenseManagement.field.periodStart' => ['Inizio periodo', 'Period start', 'Beginn des Zeitraums', 'Début de la période'],
            'expenseManagement.field.periodEnd' => ['Fine periodo', 'Period end', 'Ende des Zeitraums', 'Fin de la période'],
            'expenseManagement.field.total' => ['Totale', 'Total', 'Total', 'Total'],
            'expenseManagement.field.reimbursable' => ['Rimborsabile', 'Reimbursable', 'Erstattungsfähig', 'Remboursable'],
            'expenseManagement.field.nonReimbursable' => ['Non rimborsabile (carta aziendale)', 'Non-reimbursable (corporate card)', 'Nicht erstattungsfähig (Firmenkarte)', 'Non remboursable (carte d’entreprise)'],
            'expenseManagement.field.taxable' => ['Imponibile', 'Taxable', 'Steuerpflichtig', 'Imposable'],
            'expenseManagement.field.exempt' => ['Esente', 'Exempt', 'Steuerfrei', 'Exonéré'],
            'expenseManagement.field.approved' => ['Approvato', 'Approved', 'Genehmigt', 'Approuvé'],
            'expenseManagement.field.approvedAmount' => ['Importo approvato (facoltativo)', 'Approved amount (optional)', 'Genehmigter Betrag (optional)', 'Montant approuvé (facultatif)'],
            'expenseManagement.field.comment' => ['Commento', 'Comment', 'Kommentar', 'Commentaire'],
            'expenseManagement.field.rejectionReason' => ['Motivo del rifiuto', 'Rejection reason', 'Ablehnungsgrund', 'Motif du refus'],
            'expenseManagement.field.violations' => ['Violazioni', 'Violations', 'Verstösse', 'Violations'],
            'expenseManagement.field.severity' => ['Gravità', 'Severity', 'Schweregrad', 'Gravité'],
            'expenseManagement.field.expenseDate' => ['Data', 'Date', 'Datum', 'Date'],
            'expenseManagement.field.category' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'expenseManagement.field.merchant' => ['Esercente', 'Merchant', 'Händler', 'Commerçant'],
            'expenseManagement.field.merchantTaxId' => ['P/IVA o UID dell’esercente', 'Merchant P/IVA or UID', 'P/IVA oder UID des Händlers', 'P/IVA ou IDE du commerçant'],
            'expenseManagement.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'expenseManagement.field.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'expenseManagement.field.amountBase' => ['Importo in valuta base', 'Amount in base currency', 'Betrag in Basiswährung', 'Montant en devise de base'],
            'expenseManagement.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'expenseManagement.field.exchangeRate' => ['Tasso di cambio', 'Exchange rate', 'Wechselkurs', 'Taux de change'],
            'expenseManagement.field.vatRate' => ['Aliquota IVA (%)', 'VAT rate (%)', 'MWST-Satz (%)', 'Taux de TVA (%)'],
            'expenseManagement.field.vatAmount' => ['Importo IVA', 'VAT amount', 'MWST-Betrag', 'Montant de TVA'],
            'expenseManagement.field.paymentMethod' => ['Metodo di pagamento', 'Payment method', 'Zahlungsart', 'Mode de paiement'],
            'expenseManagement.field.paidInCash' => ['Pagata in contanti', 'Paid in cash', 'Bar bezahlt', 'Payée en espèces'],
            'expenseManagement.field.cash' => ['Contanti', 'Cash', 'Bar', 'Espèces'],
            'expenseManagement.field.billable' => ['Addebitabile al cliente', 'Billable to the customer', 'Dem Kunden verrechenbar', 'Refacturable au client'],
            'expenseManagement.field.report' => ['Nota spese', 'Expense report', 'Spesenabrechnung', 'Note de frais'],
            'expenseManagement.field.origin' => ['Partenza', 'Origin', 'Start', 'Départ'],
            'expenseManagement.field.destination' => ['Destinazione', 'Destination', 'Ziel', 'Destination'],
            'expenseManagement.field.distanceKm' => ['Distanza (km)', 'Distance (km)', 'Distanz (km)', 'Distance (km)'],
            'expenseManagement.field.roundTrip' => ['Andata e ritorno', 'Round trip', 'Hin- und Rückfahrt', 'Aller-retour'],
            'expenseManagement.field.vehicle' => ['Veicolo (codice ACI o classe)', 'Vehicle (ACI code or class)', 'Fahrzeug (ACI-Code oder Klasse)', 'Véhicule (code ACI ou classe)'],
            'expenseManagement.field.vehicleModel' => ['Marca e modello', 'Make and model', 'Marke und Modell', 'Marque et modèle'],
            'expenseManagement.field.calculationSource' => ['Fonte della tariffa', 'Rate source', 'Quelle des Ansatzes', 'Source du tarif'],
            'expenseManagement.field.annualKm' => ['Percorrenza annua', 'Annual mileage', 'Jahresfahrleistung', 'Kilométrage annuel'],
            'expenseManagement.field.country' => ['Paese (ISO)', 'Country (ISO)', 'Land (ISO)', 'Pays (ISO)'],
            'expenseManagement.field.destinationType' => ['Tipo di destinazione', 'Destination type', 'Zieltyp', 'Type de destination'],
            'expenseManagement.field.allowanceMethod' => ['Regime dell’indennità', 'Allowance method', 'Entschädigungsart', 'Régime de l’indemnité'],
            'expenseManagement.field.tripStart' => ['Inizio trasferta', 'Trip start', 'Reisebeginn', 'Début du déplacement'],
            'expenseManagement.field.tripEnd' => ['Fine trasferta', 'Trip end', 'Reiseende', 'Fin du déplacement'],
            'expenseManagement.field.ratePerDay' => ['Indennità giornaliera', 'Allowance per day', 'Entschädigung pro Tag', 'Indemnité par jour'],
            'expenseManagement.field.lodgingReimbursed' => ['Alloggio fornito o rimborsato', 'Lodging provided or reimbursed', 'Unterkunft gestellt oder erstattet', 'Hébergement fourni ou remboursé'],
            'expenseManagement.field.mealsReimbursed' => ['Vitto fornito o rimborsato', 'Meals provided or reimbursed', 'Verpflegung gestellt oder erstattet', 'Repas fournis ou remboursés'],
            'expenseManagement.field.channel' => ['Canale di pagamento', 'Payout channel', 'Auszahlungskanal', 'Canal de paiement'],
            'expenseManagement.field.executionDate' => ['Data di esecuzione', 'Execution date', 'Ausführungsdatum', 'Date d’exécution'],
            'expenseManagement.field.card' => ['Carta', 'Card', 'Karte', 'Carte'],
            'expenseManagement.section.lines' => ['Spese', 'Expenses', 'Spesen', 'Dépenses'],
            'expenseManagement.section.line' => ['Spesa', 'Expense', 'Spese', 'Dépense'],
            'expenseManagement.section.mileage' => ['Rimborso chilometrico', 'Mileage claim', 'Kilometerentschädigung', 'Indemnité kilométrique'],
            'expenseManagement.section.perDiems' => ['Diarie di trasferta', 'Travel per diems', 'Reisepauschalen', 'Indemnités de déplacement'],
            'expenseManagement.section.perDiem' => ['Diaria di trasferta', 'Travel per diem', 'Reisepauschale', 'Indemnité de déplacement'],
            'expenseManagement.section.payouts' => ['Pagamenti', 'Payouts', 'Auszahlungen', 'Paiements'],
            'expenseManagement.section.audit' => ['Cronologia', 'Audit trail', 'Verlauf', 'Historique'],
            'expenseManagement.balance.openReports' => ['Note spese aperte', 'Open reports', 'Offene Abrechnungen', 'Notes ouvertes'],
            'expenseManagement.balance.openAmount' => ['Importo aperto', 'Open amount', 'Offener Betrag', 'Montant ouvert'],
            'expenseManagement.balance.approvedUnpaid' => ['Approvato non pagato', 'Approved, not paid', 'Genehmigt, nicht bezahlt', 'Approuvé, non payé'],
            'expenseManagement.balance.advances' => ['Anticipi da compensare', 'Outstanding advances', 'Offene Vorschüsse', 'Avances à compenser'],
            'expenseManagement.balance.perDiemTaxable' => ['Diarie imponibili da inizio anno', 'Taxable per diems year to date', 'Steuerpflichtige Pauschalen seit Jahresbeginn', 'Indemnités imposables depuis le début de l’année'],
            'expenseManagement.mileage.rate' => ['Tariffa', 'Rate', 'Ansatz', 'Tarif'],
            'expenseManagement.mileage.totalKm' => ['Chilometri totali', 'Total kilometres', 'Total Kilometer', 'Kilomètres totaux'],
            'expenseManagement.mileage.amount' => ['Rimborso', 'Reimbursement', 'Entschädigung', 'Remboursement'],
            'expenseManagement.perDiem.days' => ['Giorni', 'Days', 'Tage', 'Jours'],
            'expenseManagement.perDiem.gross' => ['Indennità lorda', 'Gross allowance', 'Bruttoentschädigung', 'Indemnité brute'],
            'expenseManagement.perDiem.dailyLimit' => ['Limite esente giornaliero', 'Daily tax-free limit', 'Steuerfreies Tageslimit', 'Plafond journalier exonéré'],
            'expenseManagement.perDiem.taxFree' => ['Parte esente', 'Tax-free part', 'Steuerfreier Teil', 'Part exonérée'],
            'expenseManagement.perDiem.taxableExcess' => ['Eccedenza imponibile', 'Taxable excess', 'Steuerpflichtiger Überschuss', 'Excédent imposable'],
            'expenseManagement.workbench.file' => ['Ricevuta (immagine o PDF)', 'Receipt (image or PDF)', 'Beleg (Bild oder PDF)', 'Reçu (image ou PDF)'],
            'expenseManagement.workbench.ocrText' => ['Testo già letto dal dispositivo (facoltativo)', 'Text already read on the device (optional)', 'Bereits auf dem Gerät gelesener Text (optional)', 'Texte déjà lu sur l’appareil (facultatif)'],
            'expenseManagement.workbench.confidence' => ['Affidabilità OCR', 'OCR confidence', 'OCR-Sicherheit', 'Fiabilité OCR'],
            'expenseManagement.workbench.duplicates' => ['Possibile duplicato rilevato', 'Possible duplicate detected', 'Mögliches Duplikat erkannt', 'Doublon possible détecté'],
            'expenseManagement.workbench.viewer' => ['Anteprima ricevuta', 'Receipt preview', 'Belegvorschau', 'Aperçu du reçu'],
            'expenseManagement.workbench.selectReport' => ['Scegli la nota spese a cui aggiungere la spesa.', 'Choose the expense report to add the expense to.', 'Die Spesenabrechnung für die Spese wählen.', 'Choisissez la note de frais à laquelle ajouter la dépense.'],
            'expenseManagement.cards.transactions' => ['Transazioni della carta', 'Card transactions', 'Kartentransaktionen', 'Transactions de carte'],
            'expenseManagement.cards.lines' => ['Spese da abbinare', 'Expenses to match', 'Zuzuordnende Spesen', 'Dépenses à rapprocher'],
            'expenseManagement.cards.remaining' => ['Residuo', 'Remaining', 'Rest', 'Reste'],
            'expenseManagement.cards.covers' => ['Quota coperta', 'Part covered', 'Gedeckter Anteil', 'Part couverte'],
            'expenseManagement.cards.left' => ['Ancora da abbinare', 'Still to match', 'Noch zuzuordnen', 'Reste à rapprocher'],
            'expenseManagement.cards.pickTransaction' => ['Scegli una transazione da riconciliare.', 'Pick a transaction to reconcile.', 'Eine Transaktion zum Abstimmen wählen.', 'Choisissez une transaction à rapprocher.'],
            'expenseManagement.empty.reports' => ['Nessuna nota spese', 'No expense reports', 'Keine Spesenabrechnungen', 'Aucune note de frais'],
            'expenseManagement.empty.lines' => ['Nessuna spesa', 'No expenses', 'Keine Spesen', 'Aucune dépense'],
            'expenseManagement.empty.perDiems' => ['Nessuna diaria', 'No per diems', 'Keine Tagespauschalen', 'Aucune indemnité'],
            'expenseManagement.empty.transactions' => ['Nessuna transazione', 'No transactions', 'Keine Transaktionen', 'Aucune transaction'],
            'expenseManagement.empty.cardLines' => ['Nessuna spesa su carta da abbinare in questa nota', 'No card expenses to match in this report', 'Keine Kartenspesen zum Zuordnen in dieser Abrechnung', 'Aucune dépense par carte à rapprocher dans cette note'],
            'expenseManagement.empty.violations' => ['Nessuna violazione', 'No violations', 'Keine Verstösse', 'Aucune violation'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'expenseManagement.action.all' => ['Tutti', 'All', 'Alle', 'Tous'],
            'expenseManagement.action.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'expenseManagement.action.newReport' => ['Nuova nota spese', 'New expense report', 'Neue Spesenabrechnung', 'Nouvelle note de frais'],
            'expenseManagement.action.create' => ['Crea', 'Create', 'Erstellen', 'Créer'],
            'expenseManagement.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'expenseManagement.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'expenseManagement.action.openReport' => ['Apri la nota spese', 'Open the report', 'Abrechnung öffnen', 'Ouvrir la note'],
            'expenseManagement.action.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'expenseManagement.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'expenseManagement.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'expenseManagement.action.remove' => ['Rimuovi', 'Remove', 'Entfernen', 'Supprimer'],
            'expenseManagement.action.addLine' => ['Aggiungi spesa', 'Add expense', 'Spese hinzufügen', 'Ajouter une dépense'],
            'expenseManagement.action.addMileage' => ['Aggiungi rimborso chilometrico', 'Add mileage claim', 'Kilometerentschädigung hinzufügen', 'Ajouter une indemnité kilométrique'],
            'expenseManagement.action.addPerDiem' => ['Aggiungi diaria', 'Add per diem', 'Tagespauschale hinzufügen', 'Ajouter une indemnité'],
            'expenseManagement.action.scanReceipt' => ['Scansiona ricevuta', 'Scan receipt', 'Beleg erfassen', 'Numériser un reçu'],
            'expenseManagement.action.scan' => ['Leggi ricevuta', 'Read receipt', 'Beleg auslesen', 'Lire le reçu'],
            'expenseManagement.action.submit' => ['Invia per approvazione', 'Submit for approval', 'Zur Genehmigung einreichen', 'Soumettre pour approbation'],
            'expenseManagement.action.approve' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'expenseManagement.action.reject' => ['Respingi', 'Reject', 'Ablehnen', 'Refuser'],
            'expenseManagement.action.exportPayout' => ['Esporta pagamento', 'Export payout', 'Auszahlung exportieren', 'Exporter le paiement'],
            'expenseManagement.action.confirmReimbursement' => ['Conferma rimborso', 'Confirm reimbursement', 'Erstattung bestätigen', 'Confirmer le remboursement'],
            'expenseManagement.action.cancelReport' => ['Annulla nota spese', 'Cancel report', 'Abrechnung stornieren', 'Annuler la note'],
            'expenseManagement.action.download' => ['Scarica', 'Download', 'Herunterladen', 'Télécharger'],
            'expenseManagement.action.zoomIn' => ['Ingrandisci', 'Zoom in', 'Vergrössern', 'Agrandir'],
            'expenseManagement.action.zoomOut' => ['Riduci', 'Zoom out', 'Verkleinern', 'Réduire'],
            'expenseManagement.action.rotateLeft' => ['Ruota a sinistra', 'Rotate left', 'Nach links drehen', 'Pivoter à gauche'],
            'expenseManagement.action.rotateRight' => ['Ruota a destra', 'Rotate right', 'Nach rechts drehen', 'Pivoter à droite'],
            'expenseManagement.action.reset' => ['Ripristina', 'Reset', 'Zurücksetzen', 'Réinitialiser'],
            'expenseManagement.action.reconcile' => ['Riconcilia', 'Reconcile', 'Abstimmen', 'Rapprocher'],
            'expenseManagement.action.match' => ['Abbina', 'Match', 'Zuordnen', 'Associer'],
            'expenseManagement.action.matchSplit' => ['Abbina in più quote', 'Match as split', 'Aufgeteilt zuordnen', 'Associer en plusieurs parts'],
            'expenseManagement.action.unmatch' => ['Annulla abbinamento', 'Unmatch', 'Zuordnung aufheben', 'Dissocier'],
            'expenseManagement.action.ignore' => ['Ignora', 'Ignore', 'Ignorieren', 'Ignorer'],
            'expenseManagement.action.dispute' => ['Contesta', 'Dispute', 'Beanstanden', 'Contester'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'expenseManagement.message.saved' => ['Salvato.', 'Saved.', 'Gespeichert.', 'Enregistré.'],
            'expenseManagement.message.submitted' => ['Nota spese inviata per approvazione.', 'Expense report submitted for approval.', 'Spesenabrechnung zur Genehmigung eingereicht.', 'Note de frais soumise pour approbation.'],
            'expenseManagement.message.approved' => ['Nota spese approvata.', 'Expense report approved.', 'Spesenabrechnung genehmigt.', 'Note de frais approuvée.'],
            'expenseManagement.message.rejected' => ['Nota spese respinta.', 'Expense report rejected.', 'Spesenabrechnung abgelehnt.', 'Note de frais refusée.'],
            'expenseManagement.message.payoutExported' => ['Pagamento esportato.', 'Payout exported.', 'Auszahlung exportiert.', 'Paiement exporté.'],
            'expenseManagement.message.reimbursed' => ['Rimborso confermato.', 'Reimbursement confirmed.', 'Erstattung bestätigt.', 'Remboursement confirmé.'],
            'expenseManagement.message.cancelled' => ['Nota spese annullata.', 'Expense report cancelled.', 'Spesenabrechnung storniert.', 'Note de frais annulée.'],
            'expenseManagement.message.lineRemoved' => ['Spesa rimossa.', 'Expense removed.', 'Spese entfernt.', 'Dépense supprimée.'],
            'expenseManagement.message.perDiemRemoved' => ['Diaria rimossa.', 'Per diem removed.', 'Tagespauschale entfernt.', 'Indemnité supprimée.'],
            'expenseManagement.message.card.matched' => ['Transazione riconciliata.', 'Transaction matched.', 'Transaktion abgestimmt.', 'Transaction rapprochée.'],
            'expenseManagement.message.card.unmatched' => ['Abbinamento annullato.', 'Match removed.', 'Zuordnung aufgehoben.', 'Association supprimée.'],
            'expenseManagement.message.card.ignore' => ['Transazione ignorata.', 'Transaction ignored.', 'Transaktion ignoriert.', 'Transaction ignorée.'],
            'expenseManagement.message.card.dispute' => ['Transazione contestata.', 'Transaction disputed.', 'Transaktion beanstandet.', 'Transaction contestée.'],
            'expenseManagement.cards.importTitle' => ['Importa estratto conto della carta', 'Import card statement', 'Kartenabrechnung importieren', 'Importer le relevé de carte'],
            'expenseManagement.cards.importHint' => ['CSV con le colonne external_id, merchant_name, transaction_date, posting_date, amount, currency (separatore virgola o punto e virgola).', 'CSV with the columns external_id, merchant_name, transaction_date, posting_date, amount, currency (comma or semicolon separator).', 'CSV mit den Spalten external_id, merchant_name, transaction_date, posting_date, amount, currency (Komma oder Semikolon als Trennzeichen).', 'CSV avec les colonnes external_id, merchant_name, transaction_date, posting_date, amount, currency (séparateur virgule ou point-virgule).'],
            'expenseManagement.cards.csvFile' => ['File CSV', 'CSV file', 'CSV-Datei', 'Fichier CSV'],
            'expenseManagement.cards.importRows' => ['Righe valide lette: {count}', 'Valid rows read: {count}', 'Gelesene gültige Zeilen: {count}', 'Lignes valides lues : {count}'],
            'expenseManagement.action.startReview' => ['Avvia revisione', 'Start review', 'Prüfung starten', 'Démarrer la révision'],
            'expenseManagement.action.importStatement' => ['Importa estratto conto', 'Import statement', 'Abrechnung importieren', 'Importer le relevé'],
            'expenseManagement.action.import' => ['Importa', 'Import', 'Importieren', 'Importer'],
            'expenseManagement.message.reviewStarted' => ['Revisione avviata.', 'Review started.', 'Prüfung gestartet.', 'Révision démarrée.'],
            'expenseManagement.message.card.imported' => ['Estratto conto importato.', 'Statement imported.', 'Abrechnung importiert.', 'Relevé importé.'],
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
