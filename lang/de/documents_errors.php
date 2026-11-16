<?php

declare(strict_types=1);

return [
    'attachment' => [
        'file_missing' => 'Die Datei dieses Anhangs fehlt im Speicher.',
    ],
    'link' => [
        'record_not_found' => 'Dieser Datensatz existiert nicht.',
    ],
    'document' => [
        'checked_out' => 'Dieses Dokument ist ausgecheckt: Checken Sie es zuerst ein oder brechen Sie das Auschecken ab.',
        'under_retention' => 'Dieses Dokument unterliegt der Aufbewahrung bis :date.',
        'not_checked_out' => 'Dieses Dokument ist nicht ausgecheckt.',
        'checked_out_by_other' => 'Dieses Dokument ist von einer anderen Person ausgecheckt.',
        'legal_hold' => 'Dieses Dokument unterliegt einer Rechtssperre und kann nicht geändert werden.',
    ],
    'approval' => [
        'template_inactive' => 'Die Freigabevorlage ist nicht aktiv.',
        'template_without_steps' => 'Die Freigabevorlage hat keine Schritte.',
        'already_pending' => 'Für dieses Dokument läuft bereits eine Freigabeanfrage.',
        'not_pending' => 'Diese Freigabeanfrage läuft nicht mehr.',
        'not_approver' => 'Sie sind nicht der Freigebende des aktuellen Schritts.',
        'cannot_cancel' => 'Nur der Antragsteller oder ein Administrator kann diese Anfrage abbrechen.',
        'template_in_use' => 'Der Freigabeweg kann sich nicht ändern, solange eine Anfrage läuft.',
        'role_cannot_decide' => 'Diese Rolle kann einen Schritt nicht entscheiden: Sie hat keinen Schreibzugriff auf Dokumente.',
    ],
    'preservation' => [
        'no_hash' => 'Ein Aufbewahrungspaket hat keinen Hash zum Verketten.',
    ],
    'ocr' => [
        'unsupported' => 'Kein OCR-Modul unterstützt dieses Format.',
    ],
];
