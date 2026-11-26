<?php

declare(strict_types=1);

return [
    'password' => [
        'breached' => 'Questa password compare in violazioni di dati note: scegline un’altra.',
    ],
    'messages' => [
        'member_unlocked' => 'L’account è stato sbloccato.',
        'scim_mapping_saved' => 'Mappatura del gruppo salvata.',
        'scim_mapping_removed' => 'Mappatura del gruppo rimossa.',
        'security_audit_retrieved' => 'Registro di audit della sicurezza recuperato.',
    ],
    'errors' => [
        'lockout_cap_too_small' => 'La durata massima del blocco non può essere inferiore alla durata base.',
        'unknown_event_type' => 'Tipo di evento sconosciuto: :type.',
    ],
    'mail' => [
        'unknown' => 'sconosciuto',
        'locked' => [
            'subject' => ':app: il tuo account è stato bloccato',
            'intro' => 'Il tuo account :app è stato bloccato per :minutes minuti dopo troppe password errate.',
            'until' => 'Bloccato fino a: :date',
            'ip' => 'Indirizzo IP dei tentativi: :ip',
            'device' => 'Dispositivo: :device',
            'security' => 'Se non sei stato tu, qualcuno potrebbe stare cercando di indovinare la tua password: cambiala appena il blocco termina e attiva l’autenticazione a due fattori. I blocchi ripetuti allungano il blocco successivo.',
        ],
        'unlocked' => [
            'subject' => ':app: il tuo account è stato sbloccato',
            'intro' => 'Un amministratore della tua organizzazione ha sbloccato il tuo account :app: puoi accedere di nuovo.',
            'security' => 'Se non l’hai richiesto, contatta l’amministratore della tua organizzazione.',
        ],
    ],
];
