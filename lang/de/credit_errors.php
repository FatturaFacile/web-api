<?php

declare(strict_types=1);

return [
    'customer' => [
        'missing' => 'Der Kunde existiert in diesem Unternehmen nicht.',
    ],
    'scorecard' => [
        'already_configured' => 'Das Unternehmen hat bereits Scorecard-Faktoren oder Ratingklassen: Ändern Sie diese, statt die Standardwerte anzulegen.',
        'missing_factors' => 'Die Scorecard hat keinen aktiven Faktor: Fügen Sie vor der Bewertung mindestens einen Faktor hinzu.',
        'no_band' => 'Keine Ratingklasse enthält den Score :score: Passen Sie die Scorebereiche der Ratingklassen an.',
        'manual_missing' => 'Geben Sie für den manuellen Faktor :code einen Wert von 0 bis 100 ein.',
    ],
    'band' => [
        'overlap' => 'Der Scorebereich überschneidet sich mit einer anderen Ratingklasse.',
        'code_taken' => 'Eine andere Ratingklasse verwendet diesen Code bereits.',
    ],
    'score' => [
        'missing' => 'Der Kunde hat noch keinen Score: Bewerten Sie den Kunden, bevor Sie einen Vorschlag anfordern.',
    ],
    'limit' => [
        'pending_exists' => 'Für den Kunden wartet bereits ein Kreditlimit-Antrag auf eine Entscheidung.',
        'amount_missing' => 'Geben Sie das beantragte Limit ein: Es gibt keinen Score, aus dem eines vorgeschlagen werden könnte.',
        'not_pending' => 'Nur ein offener Antrag kann entschieden oder storniert werden.',
        'same_user' => 'Wer den Antrag gestellt hat, darf ihn nicht entscheiden.',
        'note_required' => 'Das Limit liegt über dem Vorschlag von :proposed: Begründen Sie die Entscheidung in einer Notiz.',
        'valid_until_past' => 'Das Limit kann nicht bis zu einem vergangenen Tag gelten.',
    ],
    'insurance' => [
        'dates' => 'Die Police muss nach ihrem Beginn enden.',
        'policy_not_active' => 'Die Police ist heute nicht in Kraft.',
        'period' => 'Die Deckung kann nicht über das Ende der Police hinausgehen.',
        'duplicate' => 'Der Kunde hat unter dieser Police bereits eine beantragte oder gewährte Deckung.',
        'total_exceeded' => 'Die unter dieser Police gewährten Deckungen (:granted) würden die Gesamtdeckung überschreiten.',
        'policy_has_covers' => 'Die Police hat Deckungen: Stornieren Sie sie, statt sie zu löschen.',
    ],
    'strategy' => [
        'days_order' => 'Die Tage nach Fälligkeit dürfen nicht kleiner sein als die des vorherigen Schritts.',
        'in_use' => 'Ein offener Fall arbeitet bereits mit den Schritten dieser Strategie.',
        'default_required' => 'Legen Sie stattdessen eine andere Strategie als Standard fest.',
    ],
    'case' => [
        'no_overdue' => 'Der Kunde hat keine überfällige Rechnung zum Einziehen.',
        'invoice_not_overdue' => 'Einige Rechnungen sind keine offenen, überfälligen Rechnungen des Kunden.',
        'invoice_in_case' => 'Einige Rechnungen befinden sich bereits in einem offenen Inkassofall.',
        'closed' => 'Der Fall ist geschlossen.',
        'disputed' => 'Der Fall hat eine offene Reklamation: Entscheiden Sie diese zuerst.',
        'promise_pending' => 'Der Fall hat ein offenes Zahlungsversprechen: Schließen Sie es zuerst ab.',
        'no_strategy' => 'Der Fall hat keine aktive Mahnstrategie.',
        'no_more_steps' => 'Die Strategie hat keinen weiteren Schritt.',
        'step_not_due' => 'Der nächste Schritt ist :days Tage nach Fälligkeit fällig: Die älteste Rechnung ist :overdue Tage überfällig.',
        'open_disputes' => 'Der Fall hat offene Reklamationen: Entscheiden Sie diese vor dem Schließen.',
        'still_outstanding' => 'Der Fall kann nicht abgeschlossen werden: :amount sind noch offen.',
    ],
    'promise' => [
        'pending_exists' => 'Der Fall hat bereits ein offenes Zahlungsversprechen.',
        'past_date' => 'Das versprochene Datum darf nicht in der Vergangenheit liegen.',
        'amount_exceeds' => 'Der versprochene Betrag liegt über dem offenen Betrag von :outstanding.',
        'not_pending' => 'Das Versprechen ist bereits abgeschlossen.',
        'paid_required' => 'Geben Sie den gezahlten Betrag ein, um das Versprechen als eingehalten zu markieren.',
        'paid_below' => 'Der gezahlte Betrag liegt unter den versprochenen :promised: Markieren Sie das Versprechen als gebrochen.',
    ],
    'dispute' => [
        'invoice_not_open' => 'Die Rechnung ist keine offene Rechnung dieses Kunden.',
        'amount_exceeds' => 'Der reklamierte Betrag liegt über dem, was auf der Rechnung noch offen ist (:outstanding).',
        'case_mismatch' => 'Der Fall ist kein offener Fall dieses Kunden.',
        'not_open' => 'Die Reklamation ist bereits entschieden oder nicht offen.',
        'credit_required' => 'Geben Sie den dem Kunden gutgeschriebenen Betrag ein.',
        'credit_exceeds' => 'Der gutgeschriebene Betrag liegt über den reklamierten :disputed.',
    ],
];
