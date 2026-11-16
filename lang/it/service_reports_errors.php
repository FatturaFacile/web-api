<?php

declare(strict_types=1);

return [
    'consolidated.min_two' => 'Servono almeno due rapporti di intervento per generare una fattura consolidata.',
    'consolidated.not_found' => 'Uno o più rapporti di intervento non sono stati trovati.',
    'consolidated.same_customer' => 'Tutti i rapporti di intervento consolidati devono appartenere allo stesso cliente/contatto.',
    'consolidated.cancelled' => 'Il rapporto di intervento :number è annullato e non può essere fatturato.',
    'consolidated.not_signed' => 'Il rapporto di intervento :number non è firmato dal cliente.',
    'consolidated.already_invoiced' => 'Il rapporto di intervento :number è già stato fatturato.',
    'consolidated.no_billable_lines' => 'Nessuno dei rapporti di intervento selezionati contiene righe fatturabili.',
    'invoice.cancelled' => 'I rapporti di intervento annullati non possono essere fatturati.',
    'invoice.not_signed' => 'Il rapporto di intervento deve essere firmato dal cliente prima di generare una fattura.',
    'invoice.already_invoiced' => 'Questo rapporto di intervento è già stato fatturato.',
    'invoice.no_billable_lines' => 'Il rapporto di intervento non ha righe fatturabili.',
    'save.signed_locked' => 'I rapporti di intervento firmati non possono essere modificati.',
    'save.invoiced_locked' => 'I rapporti di intervento fatturati non possono essere modificati.',
    'sign.already_signed' => 'Questo rapporto di intervento è già firmato.',
    'sign.invalid_signature' => 'Il contenuto dell\'immagine della firma PNG non è valido.',
];
