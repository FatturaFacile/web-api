<?php

declare(strict_types=1);

return [
    'audit' => [
        'only_planned_editable' => 'L\'audit è :status: solo un audit pianificato può essere modificato.',
        'checklist_pending' => ':count voci della checklist non hanno ancora un esito.',
        'results_in_progress_only' => 'Gli esiti possono essere registrati mentre l\'audit è in corso.',
        'findings_in_progress_only' => 'I rilievi si aprono mentre l\'audit è in corso.',
        'status_expected' => 'L\'audit è :status: atteso :expected.',
    ],
    'capa' => [
        'nonconformity_closed' => 'La non conformità è :status: non si può aprire una nuova azione.',
        'action_locked' => 'L\'azione è :status e non può essere modificata.',
        'status_expected' => 'L\'azione è :status: atteso :expected.',
    ],
    'nonconformity' => [
        'actions_unsettled' => 'Ogni azione correttiva e preventiva deve essere verificata o annullata prima della chiusura: :numbers.',
        'root_cause_required' => 'Una non conformità maggiore o critica richiede una causa radice prima della chiusura.',
        'verified_action_required' => 'Una non conformità maggiore o critica richiede almeno un\'azione correttiva verificata prima della chiusura.',
        'status_expected' => 'La non conformità è :status: atteso :expected.',
        'locked' => 'La non conformità è :status e non può essere modificata.',
        'mfg_reference_source' => 'Un riferimento di produzione è ammesso solo per l\'origine produzione.',
        'mfg_not_found' => 'La non conformità di produzione non esiste in questa azienda.',
    ],
    'procedure' => [
        'code_exists' => 'Esiste già una procedura con questo codice: revisionala per creare una nuova versione.',
        'not_editable' => 'La procedura è :status: solo una procedura in bozza o rifiutata può essere modificata.',
        'submit_status' => 'La procedura è :status: si invia solo una procedura in bozza o rifiutata.',
        'document_required' => 'Collega il documento della procedura prima di inviarla in approvazione.',
        'workflow_start_failed' => 'Il flusso di approvazione non può essere avviato: :reason',
        'decided_by_workflow' => 'Questa procedura è decisa dal suo flusso di approvazione: usa la posta in arrivo dei flussi.',
        'obsolete_only_approved' => 'Solo una procedura approvata può essere resa obsoleta.',
        'revise_only_approved' => 'Si revisiona solo una procedura approvata.',
        'author_cannot_approve' => 'L\'autore di una procedura non può approvarla.',
        'not_pending' => 'La procedura è :status, non in attesa di approvazione.',
        'document_not_found' => 'Il documento non esiste in questa azienda.',
    ],
];
