<?php

declare(strict_types=1);

return [
    'sheet' => [
        'summary' => 'Zusammenfassung',
        'breakdown' => 'Aufschlüsselung',
        'versions' => 'Versionen',
        'rolling' => 'Rolling Forecast',
        'variance_account' => 'Abweichung je Konto',
        'variance_cost_center' => 'Abweichung je Kostenstelle',
        'volume_price' => 'Menge und Preis',
        'rules' => 'Ausgabenregeln',
        'commitments' => 'Offene Verpflichtungen',
        'overrides' => 'Ausnahmen',
    ],
    'field' => [
        'item' => 'Position', 'value' => 'Wert', 'version' => 'Version', 'fiscal_year' => 'Geschäftsjahr', 'type' => 'Typ', 'status' => 'Status',
        'currency' => 'Währung', 'approved_at' => 'Genehmigt am', 'through_period' => 'Bis Periode', 'budget' => 'Budget', 'actual' => 'Ist',
        'variance' => 'Abweichung', 'variance_percent' => 'Abweichung %', 'committed' => 'Offene Verpflichtungen', 'available' => 'Verfügbares Budget',
        'account' => 'Konto', 'account_name' => 'Kontoname', 'cost_center' => 'Kostenstelle', 'project' => 'Projekt', 'total' => 'Summe',
        'scenario' => 'Szenario', 'revision' => 'Revision', 'reason' => 'Begründung', 'period' => 'Periode', 'forecast' => 'Prognose', 'code' => 'Code',
        'name' => 'Name', 'traffic_light' => 'Ampel', 'volume_variance' => 'Mengenabweichung', 'price_variance' => 'Preisabweichung',
        'decomposable' => 'Zerlegt', 'mode' => 'Modus', 'threshold' => 'Schwelle %', 'stages' => 'Phasen', 'active' => 'Aktiv', 'source' => 'Beleg',
        'number' => 'Nummer', 'date' => 'Datum', 'amount' => 'Betrag', 'open_amount' => 'Offener Betrag', 'scope' => 'Geltungsbereich', 'requested' => 'Angefragt',
        'approved' => 'Genehmigt', 'decision' => 'Entscheidungskommentar', 'decided_at' => 'Entschieden am', 'valid_until' => 'Gültig bis',
    ],
    'type' => ['budget' => 'Ursprüngliches Budget', 'revised' => 'Revidiertes Budget', 'forecast' => 'Prognose', 'rolling_forecast' => 'Rolling Forecast', 'scenario' => 'Szenario'],
    'status' => ['draft' => 'Entwurf', 'submitted' => 'Eingereicht', 'approved' => 'Genehmigt', 'locked' => 'Gesperrt'],
    'scenario' => ['best_case' => 'Bestfall', 'worst_case' => 'Schlechtester Fall', 'custom' => 'Benutzerdefiniert'],
    'light' => ['green' => 'Grün', 'amber' => 'Gelb', 'red' => 'Rot'],
    'mode' => ['allow' => 'Erlauben', 'warning' => 'Warnung', 'hard_block' => 'Sperre'],
    'source' => ['requisition' => 'Bestellanforderung', 'purchase_order' => 'Bestellung'],
    'scope' => ['document' => 'Beleg', 'allowance' => 'Befristetes Kontingent'],
    'override_status' => ['pending' => 'Ausstehend', 'approved' => 'Genehmigt', 'rejected' => 'Abgelehnt', 'cancelled' => 'Storniert', 'consumed' => 'Verbraucht'],
    'yes' => 'Ja',
    'no' => 'Nein',
];
