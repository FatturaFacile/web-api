<?php

declare(strict_types=1);

return [
    'dunning' => [
        'invoice_not_unpaid' => 'Die Rechnung ist keine ausgestellte, unbezahlte Rechnung dieses Abonnements.',
    ],
    'subscription' => [
        'start_date_locked' => 'Das Startdatum eines bereits abgerechneten Abonnements kann nicht geändert werden.',
        'no_quantity_item' => 'Das Abonnement hat keine Tarifposition, deren Menge geändert werden kann.',
        'already_closed' => 'Das Abonnement ist bereits beendet.',
        'pending_approval' => 'Das Abonnement wartet auf Genehmigung und kann nicht geändert werden.',
    ],
    'plan' => [
        'not_active' => 'Der Tarif ist kein aktiver Tarif dieses Unternehmens.',
        'currency_interval_mismatch' => 'Der neue Tarif muss die Währung und das Abrechnungsintervall des Abonnements haben.',
        'use_downgrade' => 'Der neue Tarif kostet weniger: Verwenden Sie ein Downgrade.',
        'use_upgrade' => 'Der neue Tarif kostet mehr: Verwenden Sie ein Upgrade.',
    ],
    'usage' => [
        'item_not_metered' => 'Die Position ist keine verbrauchsabhängige Position eines Abonnements dieses Unternehmens.',
        'subscription_closed' => 'Das Abonnement der Position ist beendet.',
        'metric_mismatch' => 'Die Kennzahl stimmt nicht mit der Kennzahl der Position überein.',
    ],
];
