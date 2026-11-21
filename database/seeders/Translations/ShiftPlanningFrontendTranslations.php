<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the workforce shift planning module (Mod 073): the roster matrix, the template palette, the swap inbox,
 * the compliance rules the API reports (SHIFT_PLANNING.RULE_<CODE>) and the push notifications. Each entry is
 * [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH. Placeholders
 * are written {name}.
 */
final class ShiftPlanningFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.SHIFT_PLANNING' => ['Pianificazione turni', 'Shift planning', 'Schichtplanung', 'Planification des services'],
            'NAV.SHIFT_PLANNING_ROSTER' => ['Piano turni', 'Roster', 'Dienstplan', 'Planning des services'],
            'NAV.SHIFT_PLANNING_TEMPLATES' => ['Modelli di turno', 'Shift templates', 'Schichtvorlagen', 'Modèles de service'],
            'PAGE_TITLE.SHIFT_PLANNING' => ['Pianificazione turni di lavoro', 'Workforce shift planning', 'Schichtplanung', 'Planification des services'],
            'PAGE_TITLE.SHIFT_TEMPLATES' => ['Modelli di turno', 'Shift templates', 'Schichtvorlagen', 'Modèles de service'],

            'SHIFT_PLANNING.TITLE' => ['Piano turni', 'Roster', 'Dienstplan', 'Planning des services'],
            'SHIFT_PLANNING.SUBTITLE' => ['Trascina i turni: i vincoli di legge, le assenze e le certificazioni sono verificati in tempo reale', 'Drag the shifts: labour law, leave and certifications are checked in real time', 'Schichten ziehen: Arbeitsgesetz, Absenzen und Zertifikate werden in Echtzeit geprüft', 'Glissez les services : droit du travail, absences et certificats sont vérifiés en temps réel'],
            'SHIFT_PLANNING.LOAD_ERROR' => ['Impossibile caricare il piano turni', 'The roster could not be loaded', 'Der Dienstplan konnte nicht geladen werden', 'Le planning n’a pas pu être chargé'],
            'SHIFT_PLANNING.SAVE_ERROR' => ['Impossibile salvare la modifica', 'The change could not be saved', 'Die Änderung konnte nicht gespeichert werden', 'La modification n’a pas pu être enregistrée'],
            'SHIFT_PLANNING.NO_PERIOD' => ['Nessun piano per questo periodo', 'No roster for this period', 'Kein Dienstplan für diesen Zeitraum', 'Aucun planning pour cette période'],
            'SHIFT_PLANNING.VIEW' => ['Vista', 'View', 'Ansicht', 'Vue'],
            'SHIFT_PLANNING.WEEKLY' => ['Settimanale', 'Weekly', 'Wöchentlich', 'Hebdomadaire'],
            'SHIFT_PLANNING.MONTHLY' => ['Mensile', 'Monthly', 'Monatlich', 'Mensuel'],
            'SHIFT_PLANNING.GROUP_BY' => ['Raggruppa per', 'Group by', 'Gruppieren nach', 'Grouper par'],
            'SHIFT_PLANNING.BY_EMPLOYEE' => ['Dipendente', 'Employee', 'Mitarbeitende', 'Employé'],
            'SHIFT_PLANNING.BY_ROLE' => ['Ruolo / postazione', 'Role / workstation', 'Rolle / Arbeitsplatz', 'Rôle / poste'],
            'SHIFT_PLANNING.PREVIOUS' => ['Periodo precedente', 'Previous period', 'Vorheriger Zeitraum', 'Période précédente'],
            'SHIFT_PLANNING.NEXT' => ['Periodo successivo', 'Next period', 'Nächster Zeitraum', 'Période suivante'],
            'SHIFT_PLANNING.STATUS_DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'SHIFT_PLANNING.STATUS_PUBLISHED' => ['Pubblicato', 'Published', 'Veröffentlicht', 'Publié'],
            'SHIFT_PLANNING.STATUS_LOCKED' => ['Bloccato', 'Locked', 'Gesperrt', 'Verrouillé'],
            'SHIFT_PLANNING.GENERATE' => ['Genera piano', 'Generate roster', 'Dienstplan erstellen', 'Générer un planning'],
            'SHIFT_PLANNING.GENERATED' => ['Piano generato', 'Roster generated', 'Dienstplan erstellt', 'Planning généré'],
            'SHIFT_PLANNING.PUBLISH' => ['Pubblica e notifica', 'Publish and notify', 'Veröffentlichen und benachrichtigen', 'Publier et notifier'],
            'SHIFT_PLANNING.PUBLISHED' => ['Piano pubblicato, i dipendenti sono stati avvisati', 'Roster published, the employees were notified', 'Dienstplan veröffentlicht, die Mitarbeitenden wurden benachrichtigt', 'Planning publié, les employés ont été avertis'],
            'SHIFT_PLANNING.LOCK' => ['Blocca piano', 'Lock roster', 'Dienstplan sperren', 'Verrouiller le planning'],
            'SHIFT_PLANNING.LOCK_CONFIRM' => ['Dopo il blocco il piano non è più modificabile. Continuare?', 'A locked roster can no longer be changed. Continue?', 'Ein gesperrter Dienstplan kann nicht mehr geändert werden. Fortfahren?', 'Un planning verrouillé ne peut plus être modifié. Continuer ?'],
            'SHIFT_PLANNING.LOCKED' => ['Piano bloccato', 'Roster locked', 'Dienstplan gesperrt', 'Planning verrouillé'],
            'SHIFT_PLANNING.CHANGE_REFUSED' => ['Modifica rifiutata', 'Change refused', 'Änderung abgelehnt', 'Modification refusée'],
            'SHIFT_PLANNING.SUMMARY' => ['Riepilogo del piano', 'Roster summary', 'Zusammenfassung des Dienstplans', 'Résumé du planning'],
            'SHIFT_PLANNING.LABOR_COST' => ['Costo del lavoro stimato', 'Estimated labour cost', 'Geschätzte Personalkosten', 'Coût du travail estimé'],
            'SHIFT_PLANNING.SCHEDULED_HOURS' => ['Ore pianificate', 'Scheduled hours', 'Geplante Stunden', 'Heures planifiées'],
            'SHIFT_PLANNING.OPEN_SHIFTS' => ['Turni scoperti', 'Open shifts', 'Offene Schichten', 'Services ouverts'],
            'SHIFT_PLANNING.ISSUES' => ['Blocchi / avvisi', 'Blocking / warnings', 'Blockiert / Warnungen', 'Bloquants / avertissements'],
            'SHIFT_PLANNING.PALETTE' => ['Modelli di turno (trascina sulla griglia)', 'Shift templates (drag onto the grid)', 'Schichtvorlagen (auf das Raster ziehen)', 'Modèles de service (glisser sur la grille)'],
            'SHIFT_PLANNING.EMPLOYEE' => ['Dipendente', 'Employee', 'Mitarbeitende', 'Employé'],
            'SHIFT_PLANNING.ROLE' => ['Ruolo / competenza', 'Role / skill', 'Rolle / Kompetenz', 'Rôle / compétence'],
            'SHIFT_PLANNING.NO_ROLE' => ['Senza ruolo', 'No role', 'Ohne Rolle', 'Sans rôle'],
            'SHIFT_PLANNING.COVERAGE' => ['Copertura', 'Coverage', 'Abdeckung', 'Couverture'],
            'SHIFT_PLANNING.COVERAGE_OK' => ['Coperto', 'Covered', 'Abgedeckt', 'Couvert'],
            'SHIFT_PLANNING.COVERAGE_LOW' => ['Copertura parziale', 'Partly covered', 'Teilweise abgedeckt', 'Partiellement couvert'],
            'SHIFT_PLANNING.COVERAGE_CRITICAL' => ['Sottodimensionato', 'Understaffed', 'Unterbesetzt', 'Sous-effectif'],
            'SHIFT_PLANNING.OPEN_COUNT' => ['{count} scoperti', '{count} open', '{count} offen', '{count} ouverts'],
            'SHIFT_PLANNING.OPEN_ROW' => ['Turni scoperti', 'Open shifts', 'Offene Schichten', 'Services ouverts'],
            'SHIFT_PLANNING.DAY_COST' => ['Costo del giorno', 'Cost of the day', 'Kosten des Tages', 'Coût du jour'],
            'SHIFT_PLANNING.MOVE_SHIFT' => ['Assegna il turno a un altro dipendente', 'Assign the shift to another employee', 'Schicht einer anderen Person zuweisen', 'Attribuer le service à un autre employé'],
            'SHIFT_PLANNING.TAG_NIGHT' => ['Notte', 'Night', 'Nacht', 'Nuit'],
            'SHIFT_PLANNING.TAG_SUNDAY' => ['Domenica', 'Sunday', 'Sonntag', 'Dimanche'],
            'SHIFT_PLANNING.TAG_HOLIDAY' => ['Festivo', 'Holiday', 'Feiertag', 'Jour férié'],
            'SHIFT_PLANNING.TAG_ON_CALL' => ['Reperibilità', 'On call', 'Pikettdienst', 'Piquet'],
            'SHIFT_PLANNING.BLOCKING_COUNT' => ['{count} errori bloccanti', '{count} blocking errors', '{count} blockierende Fehler', '{count} erreurs bloquantes'],
            'SHIFT_PLANNING.WARNING_COUNT' => ['{count} avvisi', '{count} warnings', '{count} Warnungen', '{count} avertissements'],

            'SHIFT_PLANNING.SWAP_INBOX' => ['Cambi turno', 'Shift swaps', 'Schichttausch', 'Échanges de service'],
            'SHIFT_PLANNING.SWAP_REQUEST' => ['Richiesta di cambio turno', 'Shift swap request', 'Schichttauschanfrage', 'Demande d’échange de service'],
            'SHIFT_PLANNING.SWAP_ANY' => ['qualsiasi collega qualificato', 'any qualified colleague', 'alle qualifizierten Kolleginnen und Kollegen', 'tout collègue qualifié'],
            'SHIFT_PLANNING.SWAP_DECIDED' => ['Decisione registrata', 'Decision recorded', 'Entscheid gespeichert', 'Décision enregistrée'],
            'SHIFT_PLANNING.IMPACT_PEER' => ['{name} prende il turno offerto', '{name} takes the offered shift', '{name} übernimmt die angebotene Schicht', '{name} prend le service proposé'],
            'SHIFT_PLANNING.IMPACT_REQUESTER' => ['{name} prende il turno del collega', '{name} takes the colleague’s shift', '{name} übernimmt die Schicht der Kollegin oder des Kollegen', '{name} prend le service du collègue'],
            'SHIFT_PLANNING.CANNOT_APPROVE' => ['Il cambio viola una regola bloccante: non può essere approvato', 'The swap breaks a blocking rule and cannot be approved', 'Der Tausch verletzt eine blockierende Regel und kann nicht genehmigt werden', 'L’échange enfreint une règle bloquante et ne peut pas être approuvé'],
            'SHIFT_PLANNING.APPROVE' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'SHIFT_PLANNING.REJECT' => ['Rifiuta', 'Reject', 'Ablehnen', 'Refuser'],
            'SHIFT_PLANNING.REJECT_REASON' => ['Motivo del rifiuto', 'Reason for rejecting', 'Grund der Ablehnung', 'Motif du refus'],
            'SHIFT_PLANNING.NO_SWAPS' => ['Nessuna richiesta in attesa', 'No requests waiting', 'Keine offenen Anfragen', 'Aucune demande en attente'],
            'SHIFT_PLANNING.COST_BEFORE' => ['Costo attuale', 'Current cost', 'Aktuelle Kosten', 'Coût actuel'],
            'SHIFT_PLANNING.COST_AFTER' => ['dopo il cambio', 'after the swap', 'nach dem Tausch', 'après l’échange'],
            'SHIFT_PLANNING.NO_ISSUES' => ['Nessuna violazione', 'No violations', 'Keine Verstösse', 'Aucune infraction'],

            'SHIFT_PLANNING.MODE' => ['Modalità', 'Mode', 'Modus', 'Mode'],
            'SHIFT_PLANNING.MODE_PATTERN' => ['Schema di rotazione', 'Rotation pattern', 'Rotationsmuster', 'Schéma de rotation'],
            'SHIFT_PLANNING.MODE_CLONE' => ['Copia un periodo precedente', 'Copy a previous period', 'Früheren Zeitraum kopieren', 'Copier une période précédente'],
            'SHIFT_PLANNING.PERIOD_TYPE' => ['Tipo di periodo', 'Period type', 'Zeitraumtyp', 'Type de période'],
            'SHIFT_PLANNING.START_DATE' => ['Data di inizio', 'Start date', 'Startdatum', 'Date de début'],
            'SHIFT_PLANNING.PATTERN' => ['Schema', 'Pattern', 'Muster', 'Schéma'],
            'SHIFT_PLANNING.PATTERN_5X2' => ['5x2 (lunedì-venerdì, weekend libero)', '5x2 (Monday to Friday, weekend off)', '5x2 (Montag bis Freitag, Wochenende frei)', '5x2 (lundi à vendredi, week-end libre)'],
            'SHIFT_PLANNING.PATTERN_6X6' => ['6x6 (sei giorni di lavoro, sei di riposo)', '6x6 (six days on, six days off)', '6x6 (sechs Tage Arbeit, sechs Tage frei)', '6x6 (six jours de travail, six jours de repos)'],
            'SHIFT_PLANNING.TEAM' => ['Squadra', 'Team', 'Team', 'Équipe'],
            'SHIFT_PLANNING.TEMPLATES' => ['Modelli di turno', 'Shift templates', 'Schichtvorlagen', 'Modèles de service'],
            'SHIFT_PLANNING.SOURCE_PERIOD' => ['Periodo da copiare', 'Period to copy', 'Zu kopierender Zeitraum', 'Période à copier'],

            'SHIFT_PLANNING.TEMPLATES_TITLE' => ['Modelli di turno', 'Shift templates', 'Schichtvorlagen', 'Modèles de service'],
            'SHIFT_PLANNING.TEMPLATES_SUBTITLE' => ['Mattina, pomeriggio, notte e reperibilità riutilizzabili nei piani', 'Reusable morning, afternoon, night and on-call shifts', 'Wiederverwendbare Früh-, Spät-, Nacht- und Pikettschichten', 'Services du matin, de l’après-midi, de nuit et de piquet réutilisables'],
            'SHIFT_PLANNING.NEW_TEMPLATE' => ['Nuovo modello', 'New template', 'Neue Vorlage', 'Nouveau modèle'],
            'SHIFT_PLANNING.TEMPLATE_SAVED' => ['Modello salvato', 'Template saved', 'Vorlage gespeichert', 'Modèle enregistré'],
            'SHIFT_PLANNING.CODE' => ['Codice', 'Code', 'Code', 'Code'],
            'SHIFT_PLANNING.NAME' => ['Nome', 'Name', 'Name', 'Nom'],
            'SHIFT_PLANNING.TIME' => ['Orario', 'Time', 'Zeit', 'Horaire'],
            'SHIFT_PLANNING.BREAK' => ['Pausa', 'Break', 'Pause', 'Pause'],
            'SHIFT_PLANNING.FLAGS' => ['Caratteristiche', 'Features', 'Merkmale', 'Caractéristiques'],
            'SHIFT_PLANNING.INACTIVE' => ['Non attivo', 'Inactive', 'Inaktiv', 'Inactif'],
            'SHIFT_PLANNING.ACTIVE' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'SHIFT_PLANNING.EDIT' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'SHIFT_PLANNING.START_TIME' => ['Inizio', 'Start', 'Beginn', 'Début'],
            'SHIFT_PLANNING.END_TIME' => ['Fine', 'End', 'Ende', 'Fin'],
            'SHIFT_PLANNING.COLOR' => ['Colore', 'Color', 'Farbe', 'Couleur'],
            'SHIFT_PLANNING.NIGHT_SURCHARGE' => ['Maggiorazione notturna', 'Night surcharge', 'Nachtzuschlag', 'Majoration de nuit'],

            'SHIFT_PLANNING.RULE_SCHEDULE_OVERLAP' => ['Il dipendente ha già un turno in questo orario', 'The employee already has a shift at this time', 'Die Person hat zu dieser Zeit bereits eine Schicht', 'L’employé a déjà un service à cette heure'],
            'SHIFT_PLANNING.RULE_LEAVE_APPROVED_CONFLICT' => ['Assenza approvata dal {from} al {to}', 'Approved leave from {from} to {to}', 'Genehmigte Absenz vom {from} bis {to}', 'Absence approuvée du {from} au {to}'],
            'SHIFT_PLANNING.RULE_LEAVE_PENDING_CONFLICT' => ['Richiesta di assenza in attesa dal {from} al {to}', 'Pending leave request from {from} to {to}', 'Offenes Absenzgesuch vom {from} bis {to}', 'Demande d’absence en attente du {from} au {to}'],
            'SHIFT_PLANNING.RULE_CONTRACT_NOT_FOUND' => ['Nessun contratto attivo il {date}', 'No active contract on {date}', 'Kein aktiver Vertrag am {date}', 'Aucun contrat actif le {date}'],
            'SHIFT_PLANNING.RULE_CONTRACT_WEEKLY_HOURS_EXCEEDED' => ['{scheduled_hours} ore pianificate contro {contract_hours} contrattuali nella settimana del {week_start}', '{scheduled_hours} h planned against {contract_hours} h contractual in the week of {week_start}', '{scheduled_hours} Std. geplant bei {contract_hours} Std. vertraglich in der Woche vom {week_start}', '{scheduled_hours} h planifiées pour {contract_hours} h contractuelles la semaine du {week_start}'],
            'SHIFT_PLANNING.RULE_SKILL_MISSING' => ['Il dipendente non ha la competenza o il ruolo richiesti', 'The employee lacks the required skill or role', 'Der Person fehlt die erforderliche Kompetenz oder Rolle', 'L’employé n’a pas la compétence ou le rôle requis'],
            'SHIFT_PLANNING.RULE_SAFETY_CERTIFICATION_INVALID' => ['Certificato di sicurezza obbligatorio non valido: {certification_name} (scaduto il {expired_on})', 'Mandatory safety certificate not valid: {certification_name} (expired on {expired_on})', 'Obligatorisches Sicherheitszertifikat ungültig: {certification_name} (abgelaufen am {expired_on})', 'Certificat de sécurité obligatoire non valide : {certification_name} (expiré le {expired_on})'],
            'SHIFT_PLANNING.RULE_CH_DAILY_REST_11H' => ['Riposo giornaliero di {rest_minutes} minuti: la legge (art. 15a OLL 1) richiede almeno 11 ore, in via eccezionale 8', 'Daily rest of {rest_minutes} minutes: the law (Art. 15a ArGV 1) requires at least 11 hours, exceptionally 8', 'Tägliche Ruhezeit von {rest_minutes} Minuten: das Gesetz (Art. 15a ArGV 1) verlangt mindestens 11 Stunden, ausnahmsweise 8', 'Repos quotidien de {rest_minutes} minutes : la loi (art. 15a OLT 1) exige au moins 11 heures, exceptionnellement 8'],
            'SHIFT_PLANNING.RULE_CH_DAILY_REST_REDUCED' => ['Riposo ridotto a {rest_minutes} minuti: ammesso una sola volta a settimana se la media di due settimane è 11 ore', 'Rest reduced to {rest_minutes} minutes: allowed once a week if the two-week average is 11 hours', 'Ruhezeit auf {rest_minutes} Minuten verkürzt: einmal pro Woche zulässig, wenn der Durchschnitt über zwei Wochen 11 Stunden beträgt', 'Repos réduit à {rest_minutes} minutes : admis une fois par semaine si la moyenne sur deux semaines est de 11 heures'],
            'SHIFT_PLANNING.RULE_CH_MAX_CONSECUTIVE_DAYS' => ['{consecutive_days} giorni lavorativi consecutivi: il massimo è {maximum_days} (art. 21 LL)', '{consecutive_days} consecutive working days: the maximum is {maximum_days} (Art. 21 ArG)', '{consecutive_days} aufeinanderfolgende Arbeitstage: das Maximum ist {maximum_days} (Art. 21 ArG)', '{consecutive_days} jours de travail consécutifs : le maximum est {maximum_days} (art. 21 LTr)'],
            'SHIFT_PLANNING.RULE_CH_SUNDAY_WORK_PERMIT' => ['Lavoro domenicale: serve un permesso e un riposo compensativo (art. 18-20 LL)', 'Sunday work: needs a permit and a compensatory rest (Art. 18-20 ArG)', 'Sonntagsarbeit: braucht eine Bewilligung und eine Ersatzruhe (Art. 18-20 ArG)', 'Travail du dimanche : une autorisation et un repos compensatoire sont nécessaires (art. 18-20 LTr)'],
            'SHIFT_PLANNING.RULE_CH_SUNDAY_COMPENSATORY_REST' => ['Nessun giorno libero come riposo compensativo attorno alla domenica del {sunday}', 'No free day as compensatory rest around Sunday {sunday}', 'Kein freier Tag als Ersatzruhe rund um den Sonntag {sunday}', 'Aucun jour libre en repos compensatoire autour du dimanche {sunday}'],
            'SHIFT_PLANNING.RULE_IT_DAILY_REST_11H' => ['Riposo giornaliero di {rest_minutes} minuti: servono 11 ore consecutive (D.Lgs. 66/2003, art. 7)', 'Daily rest of {rest_minutes} minutes: 11 consecutive hours are required (D.Lgs. 66/2003, Art. 7)', 'Tägliche Ruhezeit von {rest_minutes} Minuten: 11 aufeinanderfolgende Stunden sind erforderlich (D.Lgs. 66/2003, Art. 7)', 'Repos quotidien de {rest_minutes} minutes : 11 heures consécutives sont requises (D.Lgs. 66/2003, art. 7)'],
            'SHIFT_PLANNING.RULE_IT_WEEKLY_REST_35H' => ['Nessun riposo di 35 ore consecutive nella settimana del {week_start}', 'No rest of 35 consecutive hours in the week of {week_start}', 'Keine Ruhezeit von 35 aufeinanderfolgenden Stunden in der Woche vom {week_start}', 'Aucun repos de 35 heures consécutives la semaine du {week_start}'],
            'SHIFT_PLANNING.RULE_IT_WEEKLY_REST_24H' => ['Sette giorni lavorativi nella settimana del {week_start}: manca il riposo settimanale di 24 ore', 'Seven working days in the week of {week_start}: the 24-hour weekly rest is missing', 'Sieben Arbeitstage in der Woche vom {week_start}: die wöchentliche Ruhezeit von 24 Stunden fehlt', 'Sept jours de travail la semaine du {week_start} : le repos hebdomadaire de 24 heures manque'],
            'SHIFT_PLANNING.RULE_IT_NIGHT_WORK_MAX_8H' => ['Lavoratore notturno con {worked_minutes} minuti: il massimo è {maximum_minutes} nelle 24 ore (art. 13)', 'Night worker with {worked_minutes} minutes: the maximum is {maximum_minutes} in 24 hours (Art. 13)', 'Nachtarbeitende mit {worked_minutes} Minuten: das Maximum ist {maximum_minutes} in 24 Stunden (Art. 13)', 'Travailleur de nuit avec {worked_minutes} minutes : le maximum est de {maximum_minutes} sur 24 heures (art. 13)'],

            'SHIFT_PLANNING.NOTIFY_ROSTER_PUBLISHED' => ['Nuovo piano turni dal {from} al {to}', 'New roster from {from} to {to}', 'Neuer Dienstplan vom {from} bis {to}', 'Nouveau planning du {from} au {to}'],
            'SHIFT_PLANNING.NOTIFY_SHIFT_ASSIGNED' => ['Ti è stato assegnato un turno il {date}', 'A shift was assigned to you on {date}', 'Ihnen wurde am {date} eine Schicht zugewiesen', 'Un service vous a été attribué le {date}'],
            'SHIFT_PLANNING.NOTIFY_SHIFT_REMOVED' => ['Il tuo turno del {date} è stato assegnato ad altri', 'Your shift on {date} was reassigned', 'Ihre Schicht am {date} wurde neu vergeben', 'Votre service du {date} a été réattribué'],
            'SHIFT_PLANNING.NOTIFY_SHIFT_CLAIMED' => ['Turno del {date} preso in carico', 'You claimed the shift on {date}', 'Sie haben die Schicht am {date} übernommen', 'Vous avez pris le service du {date}'],
            'SHIFT_PLANNING.NOTIFY_SWAP_REQUESTED' => ['Un collega ti propone di cambiare il turno del {date}', 'A colleague offers you to swap the shift on {date}', 'Eine Kollegin oder ein Kollege bietet Ihnen die Schicht am {date} zum Tausch an', 'Un collègue vous propose d’échanger le service du {date}'],
            'SHIFT_PLANNING.NOTIFY_SWAP_ACCEPTED_BY_PEER' => ['Il collega ha accettato il cambio: attende l’approvazione del responsabile', 'Your colleague accepted the swap: it waits for the manager', 'Die Kollegin oder der Kollege hat den Tausch angenommen: er wartet auf die Genehmigung der Leitung', 'Le collègue a accepté l’échange : il attend l’approbation du responsable'],
            'SHIFT_PLANNING.NOTIFY_SWAP_DECLINED' => ['Il collega ha rifiutato il cambio turno', 'Your colleague declined the swap', 'Die Kollegin oder der Kollege hat den Tausch abgelehnt', 'Le collègue a refusé l’échange'],
            'SHIFT_PLANNING.NOTIFY_SWAP_APPROVED' => ['Cambio turno approvato', 'Shift swap approved', 'Schichttausch genehmigt', 'Échange de service approuvé'],
            'SHIFT_PLANNING.NOTIFY_SWAP_REJECTED' => ['Cambio turno rifiutato dal responsabile', 'Shift swap rejected by the manager', 'Schichttausch von der Leitung abgelehnt', 'Échange de service refusé par le responsable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = match ($locale) {
            'it' => 0,
            'en' => 1,
            'de' => 2,
            'fr' => 3,
            default => 1,
        };

        return array_map(static fn (array $texts): string => $texts[$index], self::definitions());
    }
}
