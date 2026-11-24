<?php

declare(strict_types=1);

return [
    'messages' => [
        'instance_started' => 'Genehmigungsprozess erfolgreich gestartet',
        'decision_recorded' => 'Genehmigungsentscheidung erfolgreich erfasst',
        'step_approved' => 'Genehmigungsschritt erfolgreich bestätigt',
        'step_rejected' => 'Genehmigungsschritt abgelehnt',
        'instance_completed' => 'Genehmigungsprozess erfolgreich abgeschlossen',
        'instance_cancelled' => 'Genehmigungsprozess abgebrochen',
        'token_validated' => 'Token für Ferngenehmigung erfolgreich validiert',
    ],
    'errors' => [
        'unauthorized_approver' => 'Benutzer ist nicht berechtigt oder bevollmächtigt, diesen Schritt zu genehmigen.',
        'invalid_state' => 'Der Workflow befindet sich nicht in einem für die Entscheidung gültigen Zustand.',
    ],
];
