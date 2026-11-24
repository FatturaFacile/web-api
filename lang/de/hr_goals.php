<?php

declare(strict_types=1);

return [
    'cycle_dates_invalid' => 'Das Ende des Zyklus darf nicht vor seinem Beginn liegen.',
    'cycle_closed' => 'Der Zielzyklus ist abgeschlossen: seine Ziele können nicht mehr geändert werden.',
    'goal_not_open' => 'Das Ziel ist abgeschlossen oder abgebrochen und nimmt keine Check-ins mehr an.',
    'value_required' => 'Der Wert des Key Results ist erforderlich.',
    'progress_required' => 'Der Fortschritt des Ziels ist erforderlich.',
    'progress_is_derived' => 'Der Fortschritt dieses Ziels ergibt sich aus seinen Key Results oder Unterzielen: aktualisieren Sie diese.',
    'employee_required' => 'Ein Mitarbeiterziel benötigt einen Mitarbeiter.',
    'team_required' => 'Ein Teamziel benötigt ein Team.',
    'parent_other_cycle' => 'Das übergeordnete Ziel gehört zu einem anderen Zyklus.',
    'parent_level_invalid' => 'Das übergeordnete Ziel muss auf einer höheren Ebene liegen (Unternehmen, dann Team, dann Mitarbeiter).',
    'parent_loop' => 'Ein Ziel kann nicht von sich selbst abstammen.',
];
