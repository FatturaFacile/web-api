<?php

declare(strict_types=1);

return [
    'request' => ['title' => 'Genehmigung angefragt', 'body' => ':type :label wartet auf Ihre Entscheidung. Fällig: :due. Öffnen Sie den Link, um sie zu prüfen.'],
    'reminder' => ['title' => 'Erinnerung an eine Genehmigung', 'body' => ':type :label wartet noch auf Ihre Entscheidung (fällig :due).'],
    'escalation' => ['title' => 'Genehmigung an Sie eskaliert', 'body' => ':type :label wurde nicht rechtzeitig entschieden und braucht nun Ihre Entscheidung.'],
    'approved' => ['title' => 'Antrag genehmigt', 'body' => 'Ihr Antrag (:type :label) wurde genehmigt.'],
    'rejected' => ['title' => 'Antrag abgelehnt', 'body' => 'Ihr Antrag (:type :label) wurde abgelehnt. Öffnen Sie den Link, um die Begründung zu sehen.'],
    'changes_requested' => ['title' => 'Änderungen angefordert', 'body' => 'Eine genehmigende Person hat Änderungen an Ihrem Antrag (:type :label) verlangt. Öffnen Sie den Link, um die Begründung zu sehen.'],
    'expired' => ['title' => 'Genehmigung abgelaufen', 'body' => 'Niemand hat rechtzeitig über Ihren Antrag (:type :label) entschieden. Er wurde geschlossen.'],
    'withdrawn' => ['title' => 'Antrag zurückgezogen', 'body' => 'Die antragstellende Person hat den Antrag (:type :label) zurückgezogen. Es ist nichts mehr zu entscheiden.'],
    'blocked' => ['title' => 'Genehmigung blockiert', 'body' => 'Die Genehmigung (:type :label) kann nicht fortgesetzt werden, weil keine genehmigende Person gefunden wird. Weisen Sie jemanden zu oder brechen Sie sie ab.'],
    'delegation' => ['title' => 'Stellvertretung aktiviert', 'body' => 'Sie vertreten eine Kollegin oder einen Kollegen vom :from bis :to. Deren Genehmigungen erscheinen in Ihrem Posteingang.'],
    'subject' => [
        'sales_quote' => 'Das Angebot',
        'purchase_request' => 'Die Bestellanforderung',
        'purchase_order' => 'Die Lieferantenbestellung',
        'supplier_invoice' => 'Die Lieferantenrechnung',
        'expense_report' => 'Die Spesenabrechnung',
        'leave_request' => 'Der Abwesenheitsantrag',
        'payment_batch' => 'Der Zahlungslauf',
        'supplier_master' => 'Der Lieferantenstamm',
        'dms_document' => 'Das Dokument',
        'document' => 'Das Dokument',
    ],
];
