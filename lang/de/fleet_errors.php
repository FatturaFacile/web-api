<?php

declare(strict_types=1);

return [
    'vehicle' => [
        'plate_taken' => 'Das Kennzeichen :plate wird bereits von einem anderen Fahrzeug verwendet.',
        'odometer_decrease' => 'Der Kilometerstand darf nicht sinken: das Fahrzeug steht bereits bei :current km.',
        'retired' => 'Das Fahrzeug ist :status: es kann keine neuen Zuweisungen oder Tankvorgänge erhalten.',
        'open_assignment' => 'Das Fahrzeug hat noch eine offene Fahrerzuweisung: beenden Sie sie vor dem Verkauf oder der Ausmusterung.',
        'asset_missing' => 'Das Anlagegut existiert in diesem Unternehmen nicht.',
    ],
    'odometer' => [
        'below_earlier' => 'Der Stand von :km km liegt unter dem Stand von :earlier km am :date.',
        'above_later' => 'Der Stand von :km km liegt über dem Stand von :later km am :date.',
    ],
    'assignment' => [
        'overlap' => 'Das Fahrzeug ist bereits vom :from bis :to zugewiesen.',
        'driver_required' => 'Wählen Sie einen Mitarbeiter oder geben Sie den Namen des externen Fahrers an.',
        'employee_missing' => 'Der Mitarbeiter existiert in diesem Unternehmen nicht.',
        'end_odometer_low' => 'Der Kilometerstand bei der Rückgabe darf nicht unter dem bei der Übergabe liegen.',
        'already_ended' => 'Die Zuweisung ist bereits beendet.',
    ],
    'fuel' => [
        'unit_electric' => 'Ein Elektrofahrzeug wird in kWh geladen.',
        'unit_not_electric' => 'Das Fahrzeug ist nicht elektrisch: erfassen Sie Liter oder kg.',
        'price_or_total' => 'Geben Sie den Einzelpreis oder die Gesamtkosten an.',
    ],
    'service' => [
        'schedule_mismatch' => 'Der Wartungsplan gehört zu einem anderen Fahrzeug.',
    ],
    'schedule' => [
        'due_required' => 'Geben Sie ein Intervall oder ein Fälligkeitsdatum oder Fälligkeits-Kilometer an.',
    ],
    'deadline' => [
        'not_open' => 'Die Frist wurde bereits verlängert.',
        'renewal_date' => 'Das neue Ablaufdatum muss nach dem :date liegen.',
    ],
];
