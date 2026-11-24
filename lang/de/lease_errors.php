<?php

declare(strict_types=1);

return [
    'contract' => [
        'transition' => 'Der Leasingvertrag ist :status: er kann nicht :target werden.',
        'only_draft_editable' => 'Der Leasingvertrag ist :status: nur ein Entwurf kann geändert werden. Einen aktiven Vertrag ändern Sie über eine Neubewertung.',
        'only_draft_deletable' => 'Der Leasingvertrag ist :status: nur ein Entwurf kann gelöscht werden.',
        'not_active' => 'Der Leasingvertrag ist :status: er muss aktiv sein.',
        'exempt_not_measured' => 'Ein kurzfristiger oder geringwertiger Leasingvertrag mit Befreiung wird aufwandswirksam erfasst: er hat weder Bewertung noch Plan.',
        'negative_rou' => 'Das Nutzungsrecht wäre nicht positiv: die erhaltenen Anreize übersteigen die Leasingverbindlichkeit.',
        'term_too_long' => 'Die Laufzeit darf :max Monate nicht überschreiten.',
        'term_multiple' => 'Die Laufzeit von :term Monaten muss ein ganzzahliges Vielfaches der Zahlungsperiode von :frequency Monaten sein.',
        'extension_required' => 'Geben Sie die Monate der Verlängerung an, wenn deren Ausübung hinreichend sicher ist.',
        'purchase_option_required' => 'Eine hinreichend sicher ausgeübte Kaufoption braucht ihren Preis und die Nutzungsdauer des Vermögenswerts.',
        'useful_life_short' => 'Die Nutzungsdauer des Vermögenswerts darf nicht kürzer als die Leasinglaufzeit sein.',
        'short_term_limit' => 'Ein kurzfristiger Leasingvertrag darf nicht länger als :max Monate dauern und keine hinreichend sicher ausgeübte Kaufoption enthalten.',
        'low_value_required' => 'Geben Sie den Neuwert des Vermögenswerts an, um die Befreiung für geringwertige Vermögenswerte zu wählen.',
        'low_value_limit' => 'Der Vermögenswert ist neu :value :currency wert: die Befreiung für geringwertige Vermögenswerte gilt bis :max :currency.',
    ],
    'remeasure' => [
        'effective_not_period_start' => 'Das Wirksamkeitsdatum muss der erste Tag eines Monats des Plans sein.',
        'period_already_posted' => 'Perioden bis :period sind bereits gebucht: die Neubewertung muss ab Periode :next wirken.',
        'rate_required' => 'Eine Änderung oder Neueinschätzung braucht den angepassten Diskontsatz.',
        'rate_not_allowed' => 'Eine Indexänderung behält den ursprünglichen Diskontsatz.',
        'payment_required' => 'Eine Indexänderung braucht den neuen Zahlungsbetrag.',
        'term_fixed_for_index' => 'Eine Indexänderung ändert die Restlaufzeit nicht.',
        'term_zero' => 'Die Restlaufzeit muss mindestens einen Monat betragen.',
        'term_multiple' => 'Der Leasingvertrag muss nach einer ganzen Zahl von Zahlungsperioden von :frequency Monaten enden.',
    ],
    'posting' => [
        'not_postable' => 'Der Leasingvertrag ist :status: nur ein aktiver Vertrag mit Plan kann gebucht werden.',
    ],
];
