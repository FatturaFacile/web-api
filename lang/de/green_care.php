<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Datensätze erfolgreich abgerufen.',
        'loaded' => 'Datensatz erfolgreich abgerufen.',
        'site_saved' => 'Der Standort wurde gespeichert.',
        'site_deleted' => 'Der Standort wurde gelöscht.',
        'contract_saved' => 'Der Saisonvertrag wurde gespeichert.',
        'contract_deleted' => 'Der Saisonvertrag wurde gelöscht.',
        'contract_activated' => 'Der Vertrag ist aktiv.',
        'contract_suspended' => 'Der Vertrag wurde ausgesetzt.',
        'billing_previewed' => 'Abrechnungsvorschau berechnet.',
        'invoice_generated' => 'Der Rechnungsentwurf wurde erstellt.',
        'crew_saved' => 'Die Equipe wurde gespeichert.',
        'crew_deleted' => 'Die Equipe wurde gelöscht.',
        'license_saved' => 'Die Bewilligung wurde gespeichert.',
        'license_deleted' => 'Die Bewilligung wurde gelöscht.',
        'license_checked' => 'Die Bewilligung der Person wurde geprüft.',
        'route_saved' => 'Die Tour wurde gespeichert.',
        'route_deleted' => 'Die Tour wurde gelöscht.',
        'routes_optimized' => 'Die Wochentouren wurden geplant.',
        'routes_rescheduled' => 'Die von der Wetterwarnung betroffenen Stopps wurden verschoben.',
        'weather_refreshed' => 'Die Wetteraussichten wurden aktualisiert.',
        'intervention_logged' => 'Der Einsatz wurde erfasst.',
        'photos_saved' => 'Die Fotos wurden gespeichert.',
        'treatment_recorded' => 'Die Behandlung wurde im Pflanzenschutzjournal eingetragen.',
        'slip_saved' => 'Der Grünabfall-Begleitschein wurde gespeichert.',
        'slip_deleted' => 'Der Grünabfall-Begleitschein wurde gelöscht.',
    ],

    'errors' => [
        'operator_license_missing' => 'Die Person hat keine in :jurisdiction anerkannte Pflanzenschutz-Bewilligung (PER / PNRP / OPF in der Schweiz, Patentino PAN in Italien).',
        'operator_license_expired' => 'Die Pflanzenschutz-Bewilligung der Person ist am :date nicht gültig (Ablauf :expiry).',
        'registration_number_invalid' => 'Die Zulassungsnummer des Produkts ist für :jurisdiction ungültig (Beispiel :hint).',
        'contract_not_active' => 'Der Vertrag ist nicht aktiv.',
        'contract_not_billable' => 'Ein Vertragsentwurf kann nicht abgerechnet werden: bitte zuerst aktivieren.',
        'contract_invalid_transition' => 'Der Vertrag kann den Status :status nicht wechseln.',
        'nothing_to_bill' => 'Auf diesem Vertrag gibt es nichts abzurechnen.',
        'stop_already_completed' => 'Der Stopp wurde bereits abgeschlossen.',
        'route_locked' => 'Die Tour hat bereits begonnen oder ist abgeschlossen und kann nicht geändert werden.',
        'week_start_invalid' => 'Die Woche muss an einem Montag beginnen.',
        'no_crews_available' => 'Es gibt keine aktive Equipe zum Planen.',
        'warehouse_required' => 'Für die Lagerentnahme ist ein Lager erforderlich.',
        'condominium_data_missing' => 'Für die Rechnung mit Quellensteuer ist die Steuernummer der Gemeinschaft erforderlich.',
        'weather_unavailable' => 'Die Wetteraussichten sind derzeit nicht verfügbar.',
        'file_not_found' => 'Die Datei wurde nicht gefunden.',
    ],

    'validation' => [
        'polygon' => 'Die Fläche muss ein GeoJSON-Polygon mit mindestens vier Positionen sein.',
        'waste_code' => 'Der Abfallcode ist für :jurisdiction ungültig.',
    ],

    'waste' => [
        'default_description' => 'Grünabfall aus der Gartenpflege',
    ],

    'billing' => [
        'installment' => 'Unterhaltsvertrag :contract - Rate :number von :total',
        'snow_standby' => 'Vertrag :contract - Bereitschaftspauschale Winterdienst',
        'extra_passage' => 'Zusatzdurchgang Nr. :number (:included inbegriffen) vom :date',
        'time_and_material' => 'Regie und Material :date - :site',
        'waste' => 'Grünabfall-Entsorgung :code - :kg kg (Begleitschein :slip)',
        'invoice_note' => 'Grünunterhaltsvertrag :contract - :site',
    ],

    'register' => [
        'title_it' => 'Feldheft (Quaderno di Campagna) - Register der Pflanzenschutzbehandlungen',
        'title_ch' => 'Register der Pflanzenschutzbehandlungen (BLW)',
        'company' => 'Unternehmen',
        'period' => 'Zeitraum',
        'generated' => 'Erstellt',
        'entries' => ':count Einträge',
        'columns' => [
            'date' => 'Datum',
            'site' => 'Standort',
            'crop' => 'Kultur / Fläche',
            'area' => 'Fläche (m²)',
            'pest' => 'Schädling / Unkraut',
            'product' => 'Produkt',
            'registration' => 'Zul.-Nr.',
            'ingredient' => 'Wirkstoff',
            'dose' => 'Dosis',
            'water' => 'Wasser (l)',
            'operator' => 'Anwender',
            'license' => 'Bewilligung',
            'reentry' => 'Wiederbetreten',
        ],
    ],
];
