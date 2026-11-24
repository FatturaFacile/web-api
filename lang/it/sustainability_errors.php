<?php

declare(strict_types=1);

return [
    'factor' => [
        'unit_not_allowed' => 'L\'unità :unit non è valida per il tipo di attività :type.',
        'in_use' => 'Il fattore è usato da :count registrazioni di attività: crea una nuova versione invece di cambiarne valore, unità o tipo di attività.',
        'missing_for_activity' => 'Non esiste un fattore di emissione attivo per :type nella regione :region.',
        'type_mismatch' => 'Il fattore di emissione è per :expected, non per :type.',
        'inactive' => 'Il fattore di emissione non è attivo.',
        'unit_mismatch' => 'Il fattore è espresso per :factor_unit, la quantità in :unit.',
        'latest_version_only' => 'Solo l\'ultima versione di un fattore può essere versionata di nuovo.',
    ],
    'period' => [
        'overlap' => 'Il periodo si sovrappone al periodo di rendicontazione :name.',
        'closed' => 'Il periodo di rendicontazione :name è chiuso.',
        'status_expected' => 'Il periodo di rendicontazione è :status: atteso :expected.',
    ],
    'activity' => [
        'locked' => 'Il periodo di rendicontazione :name è chiuso: i suoi dati di attività non possono cambiare.',
    ],
    'target' => [
        'status_expected' => 'L\'obiettivo è :status: atteso :expected.',
        'locked' => 'Solo un obiettivo in bozza può cambiare ambito, anno base, anno obiettivo e percentuale.',
        'year_order' => 'L\'anno obiettivo deve essere successivo all\'anno base.',
        'baseline_missing' => 'Non risultano emissioni per :scope nel :year: la baseline non può essere congelata.',
        'not_yet_decidable' => 'L\'anno obiettivo :year non è ancora concluso e l\'obiettivo non è ancora raggiunto.',
    ],
    'kpi' => [
        'inactive' => 'Il KPI non è attivo.',
        'period_closed' => 'Il periodo di rendicontazione :name è chiuso: i valori dei KPI non possono cambiare.',
    ],
    'materiality' => [
        'locked' => 'Una valutazione di materialità approvata non può essere modificata.',
        'no_topics' => 'La valutazione richiede almeno un tema prima dell\'approvazione.',
        'status_expected' => 'La valutazione è :status: atteso :expected.',
    ],
    'esrs' => [
        'datapoint_unknown' => 'Il datapoint non esiste nel catalogo dell\'azienda.',
        'approval_reserved' => 'L\'approvazione di un valore e\' riservata a titolari, amministratori e approvatori.',
        'omission_reason_required' => 'Un datapoint non applicabile richiede il motivo dell\'omissione.',
        'value_required' => 'Il datapoint :code richiede un valore prima di essere rivisto o approvato.',
    ],
    'supplier_request' => [
        'supplier_unknown' => 'Il fornitore non esiste nell\'azienda attiva.',
        'submission_invalid' => 'L\'invio non è\' un documento valido di questo fornitore per il requisito della dichiarazione di emissioni.',
        'not_scope3' => 'Il tipo di attività :type non appartiene allo Scope 3.',
        'category_mismatch' => 'Il tipo di attività :type non appartiene alla categoria Scope 3 :category.',
        'status_expected' => 'La richiesta e\' :status: atteso :expected.',
    ],
];
