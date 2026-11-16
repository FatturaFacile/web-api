<?php

declare(strict_types=1);

return [
    'mail' => [
        'invitation' => [
            'subject' => 'Sei stato invitato a unirti a :tenant',
            'title' => 'Unisciti a :tenant',
            'intro' => 'Sei stato invitato a unirti a :tenant su :app.',
            'intro_by' => ':name ti ha invitato a unirti a :tenant su :app.',
            'action' => 'Accetta l\'invito',
            'expires' => 'L\'invito scade il :date.',
            'ignore' => 'Se non ti aspettavi questo invito puoi ignorare questa email.',
        ],
    ],
    'errors' => [
        'plan_limit_title' => 'Limite del piano raggiunto',
        'plan_limit_detail' => 'Il piano di abbonamento consente al massimo :max :limit. Passa a un piano superiore per aggiungerne altri.',
        'invitation_invalid_title' => 'Invito non valido',
        'role_in_use_title' => 'Ruolo in uso',
        'delegation_invalid_title' => 'Delega non valida',
        'import_invalid_title' => 'File di importazione non valido',
    ],
];
