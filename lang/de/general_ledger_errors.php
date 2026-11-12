<?php

declare(strict_types=1);

return [
    'account' => [
        'type_locked' => 'Konto :code enthält bereits Buchungen: Kontotyp und normaler Saldo können nicht geändert werden. Legen Sie ein neues Konto an und buchen Sie mit einem Journal um.',
        'has_postings' => 'Konto :code enthält Buchungen: Deaktivieren Sie es (is_active = false), statt es zu löschen.',
        'has_sub_accounts' => 'Konto :code hat Unterkonten: Verschieben oder löschen Sie diese zuerst.',
        'is_default_posting' => 'Konto :code ist ein Standardbuchungskonto: Wählen Sie zuerst in den Buchhaltungseinstellungen ein anderes.',
        'parent_cycle' => 'Ein Konto kann nicht sich selbst oder einem seiner Unterkonten untergeordnet werden.',
    ],
    'accrual' => [
        'cutoff_outside_coverage' => 'Das Stichtagsdatum muss innerhalb des Abgrenzungszeitraums und vor dessen letztem Tag liegen.',
        'nothing_to_post' => 'Der Anteil zum Stichtag ist null: Es gibt nichts zu buchen.',
        'income_account_type' => 'Für :type muss das Erfolgsrechnungskonto vom Typ :types sein.',
        'balance_account_type' => 'Für :type muss das Bilanzkonto vom Typ :types sein.',
    ],
    'settings' => [
        'posting_account_type' => 'Das Konto für [:role] muss ein Konto vom Typ :types dieses Mandanten sein.',
        'fx_provision_account_type' => 'Das Rückstellungskonto für nicht realisierte Währungsgewinne muss vom Typ :types dieses Mandanten sein.',
    ],
    'journal' => [
        'submit_only_draft' => 'Nur ein Entwurf kann zur Genehmigung eingereicht werden; dieses Journal ist [:status].',
        'approve_only_pending' => 'Nur ein Journal, das auf Genehmigung wartet, kann genehmigt werden; dieses Journal ist [:status].',
        'four_eyes' => 'Vier-Augen-Prinzip: Das Journal muss von einer anderen Person genehmigt werden als derjenigen, die es erfasst oder eingereicht hat.',
        'reject_only_pending' => 'Nur ein Journal, das auf Genehmigung wartet, kann abgelehnt werden; dieses Journal ist [:status].',
        'needs_second_approval' => 'Dieses Journal benötigt die Genehmigung einer zweiten Person: Reichen Sie es ein und lassen Sie es genehmigen.',
        'account_not_in_chart' => 'Das Konto gehört nicht zum Kontenplan dieses Mandanten.',
        'account_inactive' => 'Konto :code ist nicht aktiv.',
        'account_automatic_only' => 'Konto :code akzeptiert nur automatische Buchungen.',
        'cost_center_required' => 'Konto :code erfordert eine Kostenstelle.',
    ],
];
