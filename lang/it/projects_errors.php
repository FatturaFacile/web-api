<?php

declare(strict_types=1);

return [
    'project' => [
        'number_already_used' => 'Questo numero di progetto è già utilizzato.',
        'cannot_start' => 'Il progetto è :status: non può essere avviato.',
        'cannot_pause' => 'Il progetto è :status: non può essere sospeso.',
        'cannot_resume' => 'Il progetto è :status: non può essere ripreso.',
        'cannot_complete' => 'Il progetto è :status: non può essere completato.',
        'no_longer_changeable' => 'Il progetto è :status: non può più essere modificato.',
        'contract_value_via_change_orders' => 'Il valore contrattuale di un progetto avviato cambia solo tramite variazioni.',
        'recognition_method_locked' => 'Il metodo di riconoscimento non può cambiare dopo che sono stati registrati costi sul progetto.',
        'cannot_delete_with_bookings' => 'Sul progetto sono registrati tempi o costi: non può essere eliminato.',
    ],
    'rate_card' => [
        'overlaps' => 'Un altro listino tariffe con lo stesso ambito si sovrappone a questo periodo di validità.',
        'managers_only' => 'Solo i responsabili dell\'azienda possono modificare tariffe e capacità valide per tutta l\'azienda.',
    ],
    'cost' => [
        'project_closed' => 'Il progetto :number è :status: non si possono più registrare costi.',
        'approve_only_pending' => 'La voce di costo è :status: si possono approvare solo le voci in attesa.',
        'reject_only_pending' => 'La voce di costo è :status: si possono rifiutare solo le voci in attesa.',
        'category_invalid' => 'La categoria di costo non è valida.',
        'total_negative' => 'Il costo totale non può essere negativo.',
        'allocation_needs_task' => 'Ogni ripartizione deve riferirsi a un task del progetto.',
        'allocation_not_100' => 'Le percentuali di ripartizione devono sommare a 100.',
    ],
    'task' => [
        'not_in_project' => 'Il task non appartiene al progetto.',
        'cannot_delete_with_bookings' => 'Su questo task sono registrati ore o costi: non può essere eliminato.',
        'has_subtasks' => 'Il task ha dei sotto-task: eliminarli o spostarli prima.',
        'parent_invalid' => 'Il task padre deve essere un altro task dello stesso progetto.',
    ],
    'phase' => [
        'not_in_project' => 'La fase non appartiene al progetto.',
    ],
    'billing' => [
        'milestone_not_completed' => 'La milestone non è ancora completata: completarla prima di fatturare.',
        'milestone_already_billed' => 'La milestone è già fatturata.',
        'milestone_no_amount' => 'La milestone non ha un importo o una percentuale di fatturazione.',
        'nothing_to_invoice' => 'Non ci sono ore o costi fatturabili approvati da fatturare fino a questa data.',
        'project_closed' => 'Il progetto :number è :status: non può più essere fatturato.',
    ],
    'change_order' => [
        'project_closed' => 'Il progetto :number è :status: non si possono aggiungere variazioni.',
        'number_already_used' => 'Questo numero di variazione è già utilizzato nel progetto.',
        'already_decided' => 'La variazione è :status: è già stata decisa.',
        'project_closed_apply' => 'Il progetto :number è :status: le variazioni non possono più essere applicate.',
        'cost_impact_mismatch' => 'L\'impatto sui costi deve essere pari alla somma delle variazioni di costo delle righe.',
        'revenue_impact_mismatch' => 'L\'impatto sui ricavi deve essere pari alla somma delle variazioni di ricavo delle righe.',
    ],
    'closing' => [
        'open_timesheets' => ':count timesheet sono ancora in bozza, inviati o rifiutati.',
        'pending_expenses' => ':count spese sono in attesa di approvazione.',
        'unbilled_time_and_materials' => 'Ore e costi fatturabili approvati per :amount non sono stati fatturati.',
        'unbilled_milestones' => ':count milestone completate non sono state fatturate.',
        'draft_invoices' => ':count fatture in bozza del progetto non sono ancora emesse.',
        'unbilled_recognised_revenue' => 'I ricavi riconosciuti superano i ricavi fatturati di :amount: fatturarli prima della chiusura.',
        'open_commitments' => 'Ordini di acquisto per :amount sono ancora aperti sul progetto.',
        'already_closed' => 'Il progetto :number è :status: non può essere chiuso di nuovo.',
    ],
    'milestone' => [
        'billed_locked' => 'La milestone è già fatturata e non può più essere modificata.',
        'already_completed' => 'La milestone è già completata.',
        'project_closed' => 'Il progetto :number è :status: le sue milestone non possono più essere modificate.',
        'not_in_project' => 'La milestone non appartiene al progetto.',
    ],
    'revenue' => [
        'project_closed' => 'Il progetto :number è :status: i ricavi non vengono più riconosciuti.',
        'no_method' => 'Il progetto non ha un metodo di riconoscimento dei ricavi: i suoi ricavi sono quelli fatturati.',
        'percentage_only_poc' => 'Una percentuale di completamento può essere indicata solo con il metodo della percentuale di completamento.',
        'budget_required' => 'Il riconoscimento cost-to-cost richiede un budget approvato per stimare il costo totale.',
        'already_recognised_for_date' => 'I ricavi sono già stati riconosciuti per questa data.',
        'already_recognised_later' => 'I ricavi sono già stati riconosciuti per una data successiva: le elaborazioni devono seguire l\'ordine delle date.',
    ],
    'schedule' => [
        'end_before_start' => 'La data di fine pianificata deve essere uguale o successiva alla data di inizio pianificata.',
        'dependency_cycle' => 'Le dipendenze dei task formano un ciclo: il percorso critico non può essere calcolato.',
        'project_closed' => 'Il progetto :number è :status: la sua pianificazione non può più essere modificata.',
    ],
    'dependency' => [
        'predecessor_other_project' => 'Il predecessore deve essere un task dello stesso progetto.',
        'self' => 'Un task non può dipendere da se stesso.',
        'already_exists' => 'Questa dipendenza esiste già.',
        'cycle' => 'Questa dipendenza creerebbe un ciclo nella pianificazione.',
    ],
    'timesheet' => [
        'approve_only_submitted' => 'Il timesheet è :status: si possono approvare solo i timesheet inviati.',
        'reject_only_submitted' => 'Il timesheet è :status: si possono rifiutare solo i timesheet inviati.',
        'locked' => 'Il timesheet è :status e bloccato: si possono modificare solo i timesheet in bozza o rifiutati.',
        'project_closed' => 'Il progetto :number è :status: non accetta più tempi.',
        'hours_range' => 'Le ore lavorate devono essere maggiori di 0 e al massimo 24.',
        'overtime_range' => 'Le ore di straordinario devono essere comprese tra 0 e le ore lavorate.',
        'day_exceeds_24' => 'La persona ha già :hours ore registrate il :date: un giorno non può superare 24 ore.',
        'rate_missing' => 'Nessuna tariffa di costo e di fatturazione è configurata per questa persona in questa data: aggiungere un listino tariffe o una risorsa di progetto.',
    ],
];
