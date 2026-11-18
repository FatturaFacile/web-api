<?php

declare(strict_types=1);

return [

    'errors' => [
        'not_dispatchable' => 'Nur ein nicht geplanter, geplanter oder bereits zugewiesener Arbeitsauftrag kann disponiert werden.',
        'window_invalid' => 'Das Ende des Einsatzes muss nach dem Beginn liegen.',
        'technician_unavailable' => 'Der Techniker ist nicht verfügbar.',
        'schedule_conflict' => 'Der Techniker hat bereits einen sich überschneidenden Einsatz.',
        'skills_missing' => 'Dem Techniker fehlen die erforderlichen Qualifikationen: :skills.',
        'no_van' => 'Der Techniker hat kein aktives Fahrzeuglager.',
        'van_stock_short' => 'Das Fahrzeuglager enthält nicht die für diesen Arbeitsauftrag benötigten Ersatzteile.',
        'parts_not_open' => 'Ersatzteile können nur auf einem zugewiesenen, nicht abgeschlossenen Arbeitsauftrag verbraucht werden.',
        'report_not_open' => 'Der Servicerapport kann nur auf einem zugewiesenen, unterwegs befindlichen oder vor Ort laufenden Arbeitsauftrag unterschrieben werden.',
        'report_exists' => 'Dieser Arbeitsauftrag hat bereits einen unterschriebenen Servicerapport.',
        'meter_regression' => 'Der Zählerstand ist niedriger als der bereits erfasste Stand dieses Geräts.',
        'report_not_invoiceable' => 'Der Servicerapport muss unterschrieben und der Arbeitsauftrag abgeschlossen sein, bevor fakturiert wird.',
        'report_already_invoiced' => 'Dieser Servicerapport wurde bereits fakturiert.',
        'nothing_billable' => 'Der gesamte Servicerapport ist durch Vertrag oder Garantie gedeckt: Es gibt nichts zu fakturieren.',
        'transition_invalid' => 'Der Arbeitsauftrag kann nicht von :from zu :to wechseln.',
    ],

    'pdf' => [
        'title' => 'Servicerapport / Arbeitsrapport',
        'report_number' => 'Rapport-Nr.',
        'work_order' => 'Arbeitsauftrag',
        'customer' => 'Kunde',
        'site' => 'Standort',
        'equipment' => 'Gerät',
        'technician' => 'Techniker',
        'signed_at' => 'Unterzeichnet am',
        'work_performed' => 'Ausgeführte Arbeiten',
        'checklist' => 'Checkliste',
        'result_pass' => 'i. O.',
        'result_fail' => 'n. i. O.',
        'result_na' => 'k. A.',
        'meter_readings' => 'Zählerstände',
        'operating_hours' => 'Betriebsstunden',
        'cycles' => 'Zyklen',
        'charges' => 'Arbeit, Anfahrt und Ersatzteile',
        'description' => 'Beschreibung',
        'quantity' => 'Menge',
        'unit_price' => 'Einzelpreis',
        'amount' => 'Betrag',
        'status' => 'Status',
        'covered' => 'Durch Vertrag/Garantie gedeckt',
        'billable' => 'Verrechenbar',
        'kind_labor' => 'Arbeitszeit (Std.)',
        'kind_travel' => 'Reisezeit (Std.)',
        'kind_callout' => 'Anfahrtspauschale',
        'kind_part' => 'Ersatzteil',
        'travel_km' => 'Anfahrt (km)',
        'total_billable' => 'Total verrechenbar',
        'total_covered' => 'Total gedeckt',
        'signature' => 'Unterschrift des Kunden',
        'signed_by' => 'Unterzeichnet von',
        'integrity_hash' => 'Integritäts-Hash (SHA-256)',
    ],

    'mail' => [
        'subject' => 'Servicerapport :number',
        'body' => 'Im Anhang finden Sie den unterschriebenen Servicerapport :number zum Einsatz bei Ihnen vor Ort.',
    ],

];
