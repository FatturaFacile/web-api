<?php

declare(strict_types=1);

return [
    'obligation' => [
        'terminated_contract' => 'Ein beendeter Vertrag nimmt keine neuen Verpflichtungen auf.',
        'item_locked' => 'Ein Eintrag mit Status :status kann nicht geändert werden.',
        'item_closed' => 'Ein abgeschlossener Eintrag bleibt aktenkundig.',
        'already_status' => 'Dieser Eintrag hat bereits den Status :status.',
    ],
    'party' => [
        'role_exists' => 'Diese Partei hat diese Rolle im Vertrag bereits.',
    ],
    'clause' => [
        'retired_no_versions' => 'Eine zurückgezogene Klausel kann keine neuen Versionen erhalten.',
        'no_version_to_approve' => 'Eine Klausel ohne Version kann nicht freigegeben werden.',
        'retired_no_reuse' => 'Eine zurückgezogene Klausel kann nicht wiederverwendet werden.',
        'rank_used' => 'Dieser Rang der Alternativformulierung wird bereits verwendet.',
        'no_fallback_rank' => 'Die Klausel hat keine Alternativformulierung mit diesem Rang.',
    ],
    'lifecycle' => [
        'locked_by_workflow' => 'Dieser Vertrag ist durch einen Freigabe-Workflow gesperrt: Der Status ändert sich, wenn der Workflow endet.',
        'status_outside' => 'Der Status [:status] liegt außerhalb des Vertragslebenszyklus und kann hier nicht geändert werden.',
        'transition_invalid' => 'Ein Vertrag kann nicht von :from nach :to wechseln.',
        'end_date_passed' => 'Das Enddatum dieses Vertrags ist überschritten: Verlängern Sie ihn mit einem neuen Enddatum, statt ihn zu aktivieren.',
    ],
    'register' => [
        'renew_status' => 'Ein Vertrag mit Status :status kann nicht verlängert werden.',
        'renew_no_end' => 'Dieser Vertrag hat kein Enddatum, das verlängert werden könnte.',
        'renew_no_term' => 'Dieser Vertrag hat keine Verlängerungslaufzeit: Geben Sie das neue Enddatum an.',
        'renew_end_after' => 'Das neue Enddatum muss nach dem aktuellen Enddatum liegen.',
    ],
    'template' => [
        'unapproved_clauses' => 'In einer aktiven Vorlage dürfen nur freigegebene Klauseln verwendet werden: :codes.',
        'wrong_kind' => 'Die Vorlage gilt für Verträge vom Typ :kind.',
        'inactive' => 'Es kann nur eine aktive Vorlage verwendet werden.',
        'clause_not_approved' => 'Jede Klausel der Vorlage muss freigegeben sein: :clause.',
        'undeclared_placeholders' => 'Nicht deklarierte Platzhalter in den Klauseln: :names.',
        'missing_variables' => 'Erforderliche Variablen fehlen: :names.',
    ],
    'redline' => [
        'same_contract' => 'Beide Versionen müssen zum selben Vertrag gehören.',
        'comment_resolved' => 'Der Kommentar ist bereits erledigt.',
        'round_open' => 'Schließen Sie zuerst die offene Verhandlungsrunde.',
        'round_closed' => 'Die Runde ist bereits geschlossen.',
    ],
    'signature' => [
        'version_mismatch' => 'Die Version gehört nicht zu diesem Vertrag.',
        'pending_exists' => 'Für diese Version gibt es bereits eine offene Signaturanfrage.',
        'only_draft_send' => 'Nur eine Anfrage im Entwurf kann gesendet werden.',
        'already_signed' => 'Dieser Unterzeichner hat bereits unterschrieben.',
        'sign_in_order' => 'Die Unterzeichner unterschreiben der Reihe nach: Ein vorheriger Unterzeichner hat noch nicht unterschrieben.',
        'only_open_cancel' => 'Nur eine Anfrage im Entwurf oder im Versand kann abgebrochen werden.',
        'expired' => 'Die Anfrage ist abgelaufen.',
        'not_open' => 'Die Anfrage wartet nicht auf Unterschriften.',
        'token_invalid' => 'Der Signaturcode ist ungültig.',
        'provider_unknown' => 'Unbekannter Signaturanbieter.',
        'link_invalid' => 'Dieser Signaturlink ist ungültig.',
    ],
];
