<?php

declare(strict_types=1);

return [

    'greeting' => 'Sehr geehrte/r :name,',
    'closing' => 'Freundliche Grüße',

    'payment_reminder' => [
        'subject' => [
            'level_1' => 'Zahlungserinnerung - Rechnung :number',
            'level_2' => 'Zahlungsaufforderung - Rechnung :number',
            'level_3' => 'Letzte Mahnung - Rechnung :number',
        ],
        'body' => [
            'level_1' => 'Die Rechnung <strong>:number</strong>, fällig am :due_date, ist noch offen. Falls Sie bereits bezahlt haben, betrachten Sie diese Nachricht bitte als gegenstandslos.',
            'level_2' => 'Trotz unserer vorherigen Erinnerung ist die Rechnung <strong>:number</strong>, fällig am :due_date, noch nicht bezahlt. Wir bitten Sie, die Zahlung so bald wie möglich zu veranlassen.',
            'level_3' => 'Dies ist die letzte Mahnung für die Rechnung <strong>:number</strong>, fällig am :due_date. Bei ausbleibender Zahlung können wir das Inkassoverfahren einleiten.',
        ],
        'amount_due' => 'Offener Betrag: <strong>:amount :currency</strong>',
    ],

    'document' => [
        'subject' => [
            'invoice' => 'Rechnung :number',
            'quote' => 'Angebot :number',
            'sales_order' => 'Auftragsbestätigung :number',
            'delivery_note' => 'Lieferschein :number',
            'work_order' => 'Arbeitsrapport :number',
        ],
        'intro' => [
            'invoice' => 'Hier sind die Angaben zur Rechnung :number.',
            'quote' => 'Hier sind die Angaben zum Angebot :number.',
            'sales_order' => 'Hier sind die Angaben zum Auftrag :number.',
            'delivery_note' => 'Hier sind die Angaben zum Lieferschein :number.',
            'work_order' => 'Hier sind die Angaben zum Arbeitsrapport :number.',
            'document' => 'Hier sind die Angaben zum Dokument :number.',
        ],
        'number' => 'Nummer',
        'date' => 'Datum',
        'total' => 'Total',
    ],

    'appointment_reminder' => [
        'subject' => 'Terminerinnerung: Termin :number am :date',
        'body' => 'Wir erinnern Sie an den Termin <strong>:title</strong> (:number) am :date um :time.',
        'address' => 'Adresse: :address',
    ],

    'portal_activity' => [
        'quote_approved' => [
            'subject' => 'Angebot :number vom Kunden genehmigt',
            'body' => ':customer hat das Angebot :number im Kundenportal genehmigt.',
        ],
        'quote_rejected' => [
            'subject' => 'Angebot :number vom Kunden abgelehnt',
            'body' => ':customer hat das Angebot :number im Kundenportal abgelehnt.',
            'reason' => 'Begründung: :reason',
        ],
        'ticket_replied' => [
            'subject' => 'Neue Kundenantwort zum Ticket :number',
            'body' => ':customer hat im Kundenportal auf das Ticket :number geantwortet.',
        ],
    ],

    'subscription_renewal' => [
        'subject' => 'Abonnementverlängerung :number',
        'body' => 'Ihr Abonnement <strong>:name</strong> (:number) endet am :end_date.',
        'auto_renew' => 'Es wird automatisch verlängert.',
        'manual_renew' => 'Es wird nicht automatisch verlängert: Bitte kontaktieren Sie uns, wenn Sie fortfahren möchten.',
    ],

    'account_statement' => [
        'subject' => 'Kontoauszug per :date',
        'intro' => 'Hier ist Ihr Kontoauszug per :date.',
        'columns' => [
            'invoice' => 'Rechnung',
            'due_date' => 'Fälligkeit',
            'balance' => 'Saldo',
        ],
        'total_due' => 'Total fällig',
        'overdue' => 'Davon überfällig',
        'no_open_items' => 'Es gibt keine offenen Posten.',
    ],

];
