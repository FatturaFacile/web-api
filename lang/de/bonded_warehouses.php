<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Liste erfolgreich abgerufen.',
        'loaded' => 'Daten erfolgreich abgerufen.',
        'authorization_saved' => 'Die Lagerbewilligung wurde gespeichert.',
        'authorization_deleted' => 'Die Lagerbewilligung wurde gelöscht.',
        'mrn_registered' => 'Der Eingang ins Lager unter der MRN wurde erfasst und die CGU-Garantie belastet.',
        'discharge_simulated' => 'Simulation der Erledigung berechnet: nichts wurde erfasst.',
        'discharge_executed' => 'Die Erledigung aus dem Lager wurde erfasst und der Garantieanteil freigegeben.',
        'vat_inbound_registered' => 'Der Zugang wurde im Register des IVA-Lagers erfasst.',
        'extraction_previewed' => 'Vorschau der Auslagerung berechnet: nichts wurde erfasst.',
        'extraction_emitted' => 'Die Auslagerung wurde erfasst und die elektronische Eigenrechnung erstellt.',
        'reconciliation_done' => 'Der AEO-Abgleich wurde durchgeführt.',
    ],

    'errors' => [
        'record_immutable' => 'Dieser Eintrag gehört zum Prüfpfad und kann weder geändert noch gelöscht werden.',
        'authorization_not_found' => 'Lagerbewilligung nicht gefunden.',
        'authorization_inactive' => 'Die Bewilligung :code ist nicht aktiv oder zum Zeitpunkt des Vorgangs nicht gültig.',
        'regime_not_customs_warehouse' => 'Diese Bewilligung betrifft ein IVA-Lager: verwenden Sie das Register nach Art. 50-bis.',
        'regime_not_vat_depot' => 'Diese Bewilligung ist kein IVA-Lager nach Art. 50-bis.',
        'declaration_type_not_allowed' => 'Die Anmeldung :declaration ist für das Regime :regime nicht zulässig.',
        'invalid_mrn' => 'Die MRN muss 18 Zeichen haben: Jahr (2 Ziffern), Land (2 Buchstaben) und 14 alphanumerische Zeichen.',
        'invalid_discharge_mrn' => 'Geben Sie die gültige MRN der Erledigungsanmeldung an.',
        'invalid_taric' => 'Der TARIC-Code muss 8 oder 10 Ziffern haben.',
        'mrn_already_registered' => 'Die MRN :mrn Position :item ist bereits erfasst.',
        'cgu_ceiling_exceeded' => 'Der Höchstbetrag der CGU-Garantie würde überschritten: verfügbar :available :currency, verlangt :requested :currency.',
        'cgu_release_exceeds_utilized' => 'Die Freigabe übersteigt die beanspruchte Garantie.',
        'ceiling_below_utilized' => 'Der Höchstbetrag darf nicht unter der bereits beanspruchten Garantie (:utilized) liegen.',
        'authorization_in_use' => 'Die Bewilligung hat Partien oder eine belastete Garantie und kann nicht gelöscht werden.',
        'quantity_must_be_positive' => 'Die Menge muss grösser als null sein.',
        'insufficient_remaining_quantity' => 'Die Restmenge im Lager reicht nicht aus: verfügbar :available, verlangt :requested.',
        'insufficient_remaining_weight' => 'Das Restnettogewicht der MRN :mrn reicht nicht aus.',
        'lot_not_found' => 'MRN-Partie nicht gefunden.',
        'discharge_before_entry' => 'Das Erledigungsdatum liegt vor dem Eingang der MRN :mrn.',
        'usual_handling_code_required' => 'Geben Sie die übliche Behandlung an (Anhang 71-03 UZK-DA).',
        'fifo_needs_product_and_quantity' => 'Eine FIFO-Erledigung braucht Produkt und Menge.',
        'movement_not_inbound' => 'Die Bewegung ist kein Zugang des IVA-Lagers.',
        'movement_not_extraction' => 'Die Bewegung ist keine Auslagerung aus dem IVA-Lager.',
        'negative_value' => 'Werte dürfen nicht negativ sein.',
        'f24_required' => 'Eine TD23-Auslagerung braucht Referenz und Datum der F24-Zahlung.',
        'f24_not_allowed' => 'Die F24-Zahlung gilt nur für TD23-Auslagerungen.',
        'insufficient_depot_stock' => 'Der Bestand des IVA-Lagers reicht nicht aus: verfügbar :available, verlangt :requested.',
        'self_contact_missing' => 'Der Kontakt Ihres eigenen Unternehmens (gleiche MwSt-Nummer) fehlt: die Eigenrechnung kann nicht ausgestellt werden.',
        'unknown_dataset' => 'Export nicht verfügbar.',
    ],

    'invoice' => [
        'goods_line' => 'Auslagerung aus dem IVA-Lager, Partie :lot, Menge :quantity',
        'services_line' => 'Im IVA-Lager erbrachte Lagerungs- und Bearbeitungsleistungen',
        'note' => 'Auslagerung aus dem IVA-Lager (Art. 50-bis DL 331/1993), Bemessungsgrundlage :base, Dokument :type.',
    ],
];
