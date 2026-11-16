<?php

declare(strict_types=1);

return [
    'mail' => [
        'invitation' => [
            'subject' => 'Einladung zu :tenant',
            'title' => ':tenant beitreten',
            'intro' => 'Sie wurden eingeladen, :tenant auf :app beizutreten.',
            'intro_by' => ':name hat Sie eingeladen, :tenant auf :app beizutreten.',
            'action' => 'Einladung annehmen',
            'expires' => 'Die Einladung läuft am :date ab.',
            'ignore' => 'Wenn Sie diese Einladung nicht erwartet haben, können Sie diese E-Mail ignorieren.',
        ],
    ],
    'errors' => [
        'plan_limit_title' => 'Planlimit erreicht',
        'plan_limit_detail' => 'Der Abonnementplan erlaubt höchstens :max :limit. Wechseln Sie den Plan, um mehr hinzuzufügen.',
        'invitation_invalid_title' => 'Einladung ungültig',
        'role_in_use_title' => 'Rolle in Verwendung',
        'delegation_invalid_title' => 'Vertretung ungültig',
        'import_invalid_title' => 'Importdatei ungültig',
    ],
];
