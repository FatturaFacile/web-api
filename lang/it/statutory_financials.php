<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Dati del bilancio civilistico caricati.',
        'saved' => 'Salvato.',
        'deleted' => 'Eliminato.',
        'transitioned' => 'Stato aggiornato.',
        'generated' => 'I prospetti sono stati generati con la quadratura degli arrotondamenti.',
        'cash_flow_generated' => 'Il rendiconto finanziario è stato generato.',
        'notes_populated' => 'La nota integrativa è stata compilata dai sottoconti.',
        'xbrl_exported' => 'L\'istanza XBRL è stata esportata e validata.',
        'pdfa_generated' => 'I documenti PDF/A sono stati generati e verificati.',
        'pdfa_verified' => 'Il documento PDF/A è stato verificato.',
        'indicators_computed' => 'Gli indici e gli indicatori di crisi sono stati calcolati.',
    ],
    'errors' => [
        'unsupported_standard' => 'Lo standard contabile :standard non è supportato.',
        'fiscal_year_not_found' => 'L\'esercizio non è stato trovato.',
        'comparative_year_same' => 'L\'esercizio comparativo deve essere diverso dall\'esercizio.',
        'income_statement_method_not_allowed' => 'Il metodo di conto economico :method non è consentito per :standard.',
        'transition_not_allowed' => 'Un fascicolo in stato :from non può passare a :to.',
        'filing_requires_valid_xbrl' => 'Il deposito richiede un\'istanza XBRL esportata e senza errori di validazione.',
        'dossier_locked' => 'Il fascicolo è bloccato e non può più essere modificato.',
        'statements_not_generated' => 'Generare prima i prospetti.',
        'notes_not_populated' => 'Compilare prima la nota integrativa.',
        'artifact_missing' => 'Il file archiviato non è più disponibile.',
        'artifact_not_pdfa' => 'Il file non è un documento PDF/A.',
        'pdfa_kind_unknown' => 'Il tipo di documento :kind è sconosciuto.',
        'pdfa_not_compliant' => 'Il documento :kind generato non ha superato la verifica PDF/A e non è stato archiviato.',
        'pdfa_font_missing' => 'Il file del carattere :path per i documenti PDF/A manca.',
        'pdfa_font_invalid' => 'Il file del carattere per i documenti PDF/A non è valido (:table).',
        'pdfa_profile_missing' => 'Il profilo colore ICC per i documenti PDF/A manca.',
        'pdfa_profile_unsupported' => 'Il profilo PDF/A :profile non è supportato.',
    ],
];
