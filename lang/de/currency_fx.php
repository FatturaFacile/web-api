<?php

declare(strict_types=1);

/**
 * Meldungen der Mehrwährungs-Engine (Mod 003): die Antworten der API und die Fehler ihrer Regeln. it, de, fr und en synchron halten.
 */
return [
    'messages' => [
        'listed' => 'Datensätze erfolgreich abgerufen.',
        'resolved' => 'Wechselkurs ermittelt.',
        'rate_stored' => 'Wechselkurs gespeichert.',
        'sync_queued' => 'Synchronisierung der Wechselkurse eingereiht.',
        'exposure' => 'Währungsexposition berechnet.',
        'settings' => 'Währungseinstellungen abgerufen.',
        'settings_saved' => 'Währungseinstellungen gespeichert.',
        'revaluation_simulated' => 'Währungsbewertung simuliert.',
        'revaluation' => 'Währungsbewertung abgerufen.',
        'revaluation_posted' => 'Währungsbewertung gebucht.',
        'revaluation_reversed' => 'Währungsbewertung storniert.',
    ],
    'errors' => [
        'exchange_rate_missing' => 'Für :base/:quote ist am :date und in den :days Tagen davor kein Wechselkurs verfügbar: manuellen Kurs erfassen oder die Kurse synchronisieren.',
        'sync_already_running' => 'Eine :provider-Synchronisierung ist bereits eingereiht oder läuft.',
        'same_currency' => 'Basis- und Kurswährung müssen verschieden sein.',
        'contract_reason_required' => 'Ein Vertragskurs braucht eine Begründung.',
        'currency_in_use' => 'Diese Währungen haben noch offene Posten oder Salden und können nicht deaktiviert werden: :currencies.',
        'vat_method_not_allowed' => 'Diese MWST-Kursmethode ist für Gesellschaften in :country nicht zulässig.',
        'vat_method_locked' => 'Die MWST-Kursmethode muss mindestens eine Steuerperiode beibehalten werden, bevor sie geändert werden kann.',
        'account_not_found' => 'Das Konto existiert im Kontenplan der Gesellschaft nicht.',
        'nothing_to_revalue' => 'Am :date gibt es keine offenen Posten oder Fremdwährungskonten zu bewerten.',
        'period_end_invalid' => 'Das Periodenende ist kein gültiges Ende einer Periode vom Typ :type.',
        'revaluation_not_found' => 'Die Währungsbewertung existiert nicht.',
        'revaluation_already_posted' => 'Die Währungsbewertung ist bereits gebucht.',
        'revaluation_already_reversed' => 'Die Währungsbewertung ist bereits storniert.',
        'revaluation_not_posted' => 'Nur eine gebuchte Währungsbewertung kann storniert werden.',
        'revaluation_period_conflict' => 'Die Positionen am :date wurden bereits bewertet und gebucht: zuerst diese Bewertung stornieren.',
        'line_account_missing' => 'Die Bewertungszeile :reference hat kein Sachkonto.',
        'reversal_date_invalid' => 'Das Stornodatum muss nach dem Periodenende (:date) liegen.',
        'idempotency_key_required' => 'Der Header Idempotency-Key (8 bis 100 Zeichen) ist zum Buchen einer Bewertung erforderlich.',
    ],
];
