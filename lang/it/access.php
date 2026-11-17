<?php

declare(strict_types=1);

return [
    'errors' => [
        'user_limit' => 'Il piano dell\'azienda consente al massimo :max persone attive, inviti compresi.',
        'role_limit' => 'Il piano dell\'azienda consente al massimo :max ruoli personalizzati.',
        'role_in_use' => 'Ci sono ancora membri con questo ruolo: assegna prima un altro ruolo.',
        'delegator_not_member' => 'Il delegante non e\' un membro attivo dell\'azienda.',
        'delegate_not_member' => 'Il delegato non e\' un membro attivo dell\'azienda.',
        'delegate_is_delegator' => 'Una persona non puo\' delegare a se stessa.',
        'delegate_for_others' => 'Solo un titolare o un amministratore puo\' delegare per conto di un\'altra persona.',
        'delegate_owner_reserved' => 'Solo un titolare puo\' delegare l\'accesso di un titolare.',
        'team_member_not_member' => 'Ogni membro del team deve essere un membro dell\'azienda.',
        'already_member' => 'Questo indirizzo appartiene gia\' a un membro dell\'azienda.',
        'invitation_closed' => 'L\'invito e\' gia\' stato accettato o revocato.',
        'invitation_invalid' => 'Il link dell\'invito non e\' valido o e\' scaduto.',
        'invitation_sign_in' => 'Accedi con l\'account invitato per accettare questo invito.',
        'import_no_header' => 'Il file richiede una riga di intestazione con la colonna email.',
        'import_too_many_rows' => 'Il file ha troppe righe (al massimo :max).',
    ],
    'import' => [
        'already_member' => 'Gia\' membro.',
        'already_invited' => 'C\'e\' gia\' un invito in attesa.',
        'invalid_email' => 'L\'indirizzo e-mail non e\' valido.',
        'duplicate' => 'Ripetuto nel file.',
        'unknown_role' => 'Ruolo :role sconosciuto.',
        'unknown_team' => 'Team :team sconosciuto.',
        'failed' => 'La riga non e\' stata importata.',
    ],
    'mail' => [
        'invitation' => [
            'subject' => 'Invito a :company - :app',
            'greeting' => 'Sei stato invitato',
            'body' => ':inviter ti ha invitato a entrare in :company su :app come :role.',
            'action' => 'Accetta l\'invito',
            'expires' => 'Il link e\' valido fino al :date.',
        ],
    ],
];
