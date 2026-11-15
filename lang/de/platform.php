<?php

declare(strict_types=1);

return [
    'idempotency' => [
        'title' => 'Problem mit dem Idempotenzschlüssel',
        'invalid_key' => 'Der Header Idempotency-Key muss eine nicht leere Zeichenfolge mit höchstens 255 Zeichen sein.',
        'key_reused' => 'Dieser Idempotency-Key wurde bereits für eine andere Anfrage verwendet.',
        'in_progress' => 'Eine Anfrage mit diesem Idempotency-Key wird noch verarbeitet.',
    ],
    'conflict' => [
        'title' => 'Bearbeitungskonflikt',
        'detail' => 'Der Datensatz wurde seit dem Öffnen von jemand anderem geändert. Laden Sie ihn neu und wenden Sie Ihre Änderungen erneut an.',
    ],
    'recycle_bin' => [
        'not_purgeable' => 'Diese Art von Datensatz muss aus gesetzlichen Gründen aufbewahrt werden und kann nicht endgültig gelöscht werden.',
        'in_use' => 'Der Datensatz wird noch von anderen Datensätzen verwendet und kann nicht endgültig gelöscht werden.',
    ],
];
