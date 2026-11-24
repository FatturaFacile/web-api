<?php

declare(strict_types=1);

return [
    'cycle_dates_invalid' => 'La fine del ciclo non può precedere il suo inizio.',
    'cycle_closed' => 'Il ciclo di obiettivi è chiuso: i suoi obiettivi non possono più essere modificati.',
    'goal_not_open' => 'L\'obiettivo è completato o annullato e non accetta più aggiornamenti.',
    'value_required' => 'Il valore del risultato chiave è obbligatorio.',
    'progress_required' => 'L\'avanzamento dell\'obiettivo è obbligatorio.',
    'progress_is_derived' => 'L\'avanzamento di questo obiettivo deriva dai suoi risultati chiave o dagli obiettivi figli: aggiorna quelli.',
    'employee_required' => 'Un obiettivo individuale richiede un dipendente.',
    'team_required' => 'Un obiettivo di team richiede un team.',
    'parent_other_cycle' => 'L\'obiettivo padre appartiene a un altro ciclo.',
    'parent_level_invalid' => 'L\'obiettivo padre deve essere di livello superiore (azienda, poi team, poi dipendente).',
    'parent_loop' => 'Un obiettivo non può discendere da se stesso.',
];
