<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the MES operator board (#183): the touch-screen board of a work centre, the suspension, declaration and
 * lot dialogs, and the enum values of the phase states, suspension reasons and defect reasons shown on the web, WPF and
 * mobile clients. Each entry is [it, en, de, fr].
 */
final class MesOperatorBoardFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.MFG_MES_BOARD' => ['Board operatore MES', 'MES operator board', 'MES-Bedienerboard', 'Tableau opérateur MES'],

            'MFG_MES.TITLE' => ['Board operatore MES', 'MES operator board', 'MES-Bedienerboard', 'Tableau opérateur MES'],
            'MFG_MES.SUBTITLE' => [
                'Presa in carico, setup, lavorazione, sospensioni, pezzi e lotti della fase, con tempo uomo e tempo macchina automatici.',
                'Take-over, setup, machining, suspensions, pieces and lots of the phase, with automatic man time and machine time.',
                'Übernahme, Rüsten, Bearbeitung, Unterbrechungen, Stückzahlen und Chargen der Phase mit automatischer Personal- und Maschinenzeit.',
                'Prise en charge, réglage, usinage, suspensions, pièces et lots de la phase, avec temps homme et temps machine automatiques.',
            ],
            'MFG_MES.SELECT_WORK_CENTER' => ['Seleziona il centro di lavoro', 'Select the work centre', 'Arbeitsplatz auswählen', 'Sélectionner le poste de travail'],
            'MFG_MES.PICK_WORK_CENTER' => ['Scegli un centro di lavoro per vedere le sue fasi.', 'Pick a work centre to see its phases.', 'Wählen Sie einen Arbeitsplatz, um seine Phasen zu sehen.', 'Choisissez un poste de travail pour voir ses phases.'],
            'MFG_MES.NOTHING_TO_DO' => ['Nessuna fase da lavorare su questo centro.', 'No phase to work on at this work centre.', 'Keine Phase zu bearbeiten an diesem Arbeitsplatz.', 'Aucune phase à traiter sur ce poste.'],
            'MFG_MES.REFRESH' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'MFG_MES.ACTIONS' => ['Azioni sulla fase', 'Phase actions', 'Aktionen der Phase', 'Actions de la phase'],
            'MFG_MES.CANCEL' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],

            'MFG_MES.MAN_TIME' => ['Tempo uomo', 'Man time', 'Personalzeit', 'Temps homme'],
            'MFG_MES.MACHINE_TIME' => ['Tempo macchina', 'Machine time', 'Maschinenzeit', 'Temps machine'],
            'MFG_MES.SETUP_TIME' => ['Setup', 'Setup', 'Rüsten', 'Réglage'],
            'MFG_MES.RUN_TIME' => ['Lavorazione', 'Run', 'Bearbeitung', 'Production'],
            'MFG_MES.DOWNTIME' => ['Fermo', 'Downtime', 'Stillstand', 'Arrêt'],
            'MFG_MES.GOOD_PIECES' => ['Pezzi buoni', 'Good pieces', 'Gutteile', 'Pièces bonnes'],
            'MFG_MES.SCRAP_PIECES' => ['Pezzi scartati', 'Scrapped pieces', 'Ausschussteile', 'Pièces rebutées'],
            'MFG_MES.TARGET' => ['Quantità ordine', 'Order quantity', 'Auftragsmenge', 'Quantité de l’ordre'],

            'MFG_MES.TAKE_OVER' => ['Presa in carico', 'Take over', 'Übernehmen', 'Prendre en charge'],
            'MFG_MES.START_SETUP' => ['Avvio setup', 'Start setup', 'Rüsten starten', 'Démarrer le réglage'],
            'MFG_MES.START_RUN' => ['Inizio lavorazione', 'Start run', 'Bearbeitung starten', 'Démarrer la production'],
            'MFG_MES.START_RUN_UNATTENDED' => ['Lavorazione automatica', 'Start unattended run', 'Automatiklauf starten', 'Démarrer en automatique'],
            'MFG_MES.SUSPEND' => ['Sospendi', 'Suspend', 'Unterbrechen', 'Suspendre'],
            'MFG_MES.RESUME' => ['Riprendi', 'Resume', 'Fortsetzen', 'Reprendre'],
            'MFG_MES.DECLARE' => ['Dichiara pezzi', 'Declare pieces', 'Stückzahlen melden', 'Déclarer les pièces'],
            'MFG_MES.REGISTER_LOT' => ['Registra lotto', 'Register lot', 'Charge erfassen', 'Enregistrer le lot'],
            'MFG_MES.CLOSE_PHASE' => ['Chiudi fase', 'Close phase', 'Phase abschließen', 'Clôturer la phase'],
            'MFG_MES.CLOSE_CONFIRM_TITLE' => ['Chiudere la fase?', 'Close the phase?', 'Phase abschließen?', 'Clôturer la phase ?'],
            'MFG_MES.CLOSE_CONFIRM_BODY' => [
                'I tempi e i pezzi dichiarati diventano la conferma finale dell’operazione. Non si può più modificare.',
                'The times and declared pieces become the final confirmation of the operation. It cannot be changed afterwards.',
                'Die Zeiten und gemeldeten Stückzahlen werden zur Endrückmeldung des Arbeitsgangs. Danach ist keine Änderung mehr möglich.',
                'Les temps et les pièces déclarées deviennent la confirmation finale de l’opération. Elle ne pourra plus être modifiée.',
            ],

            'MFG_MES.DONE_TAKE_OVER' => ['Fase presa in carico', 'Phase taken over', 'Phase übernommen', 'Phase prise en charge'],
            'MFG_MES.DONE_SETUP' => ['Setup avviato', 'Setup started', 'Rüsten gestartet', 'Réglage démarré'],
            'MFG_MES.DONE_RUN' => ['Lavorazione avviata', 'Run started', 'Bearbeitung gestartet', 'Production démarrée'],
            'MFG_MES.DONE_SUSPEND' => ['Fase sospesa', 'Phase suspended', 'Phase unterbrochen', 'Phase suspendue'],
            'MFG_MES.DONE_RESUME' => ['Lavorazione ripresa', 'Run resumed', 'Bearbeitung fortgesetzt', 'Production reprise'],
            'MFG_MES.DONE_DECLARE' => ['Pezzi dichiarati', 'Pieces declared', 'Stückzahlen gemeldet', 'Pièces déclarées'],
            'MFG_MES.DONE_LOT' => ['Lotto registrato', 'Lot registered', 'Charge erfasst', 'Lot enregistré'],
            'MFG_MES.DONE_CLOSE' => ['Fase chiusa', 'Phase closed', 'Phase abgeschlossen', 'Phase clôturée'],

            'MFG_MES.SUSPEND_TITLE' => ['Motivo della sospensione', 'Reason for the suspension', 'Grund der Unterbrechung', 'Motif de la suspension'],
            'MFG_MES.SUSPEND_HINT' => ['Scegli la causale: il tempo fino alla ripresa è un fermo.', 'Pick the reason: the time until the resume is downtime.', 'Wählen Sie den Grund: Die Zeit bis zur Fortsetzung ist Stillstand.', 'Choisissez le motif : le temps jusqu’à la reprise est un arrêt.'],
            'MFG_MES.DECLARE_TITLE' => ['Dichiarazione di produzione', 'Production declaration', 'Produktionsmeldung', 'Déclaration de production'],
            'MFG_MES.DEFECT_REASON' => ['Causale di difetto', 'Defect reason', 'Fehlergrund', 'Motif de défaut'],
            'MFG_MES.LOT_TITLE' => ['Materia prima utilizzata', 'Material used', 'Verbrauchtes Material', 'Matière première utilisée'],
            'MFG_MES.COMPONENT' => ['Componente', 'Component', 'Komponente', 'Composant'],
            'MFG_MES.LOT' => ['Lotto', 'Lot', 'Charge', 'Lot'],
            'MFG_MES.SCAN_LOT' => ['Scansiona o digita il lotto', 'Scan or type the lot', 'Charge scannen oder eingeben', 'Scanner ou saisir le lot'],
            'MFG_MES.QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'MFG_MES.CONFIRM_OVER_CONSUMPTION' => ['Confermo il consumo oltre il necessario', 'I confirm consuming more than required', 'Ich bestätige den Mehrverbrauch', 'Je confirme la surconsommation'],
            'MFG_MES.KEY_BACKSPACE' => ['Cancella l’ultima cifra', 'Delete the last digit', 'Letzte Ziffer löschen', 'Effacer le dernier chiffre'],
            'MFG_MES.KEY_CLEAR' => ['Azzera', 'Clear', 'Löschen', 'Effacer'],

            'RES.V.setup' => ['Setup', 'Setup', 'Rüsten', 'Réglage'],
            'RES.V.attended' => ['Presidiata', 'Attended', 'Bedient', 'Surveillée'],
            'RES.V.unattended' => ['Automatica', 'Unattended', 'Automatisch', 'Automatique'],
            'RES.V.machine_breakdown' => ['Guasto macchina', 'Machine breakdown', 'Maschinenausfall', 'Panne machine'],
            'RES.V.material_shortage' => ['Mancanza materiale', 'Material shortage', 'Materialmangel', 'Manque de matière'],
            'RES.V.quality_hold' => ['Blocco qualità', 'Quality hold', 'Qualitätssperre', 'Blocage qualité'],
            'RES.V.break' => ['Pausa', 'Break', 'Pause', 'Pause'],
            'RES.V.shift_handover' => ['Cambio turno', 'Shift handover', 'Schichtübergabe', 'Changement d’équipe'],
            'RES.V.tool_change' => ['Cambio utensile', 'Tool change', 'Werkzeugwechsel', 'Changement d’outil'],
            'RES.V.dimensional' => ['Fuori quota', 'Out of dimension', 'Maßabweichung', 'Hors cote'],
            'RES.V.surface' => ['Difetto superficiale', 'Surface defect', 'Oberflächenfehler', 'Défaut de surface'],
            'RES.V.material_defect' => ['Difetto del materiale', 'Material defect', 'Materialfehler', 'Défaut de matière'],
            'RES.V.tool_wear' => ['Usura utensile', 'Tool wear', 'Werkzeugverschleiß', 'Usure d’outil'],
            'RES.V.setup_error' => ['Errore di setup', 'Setup error', 'Rüstfehler', 'Erreur de réglage'],
            'RES.V.operator_error' => ['Errore operatore', 'Operator error', 'Bedienfehler', 'Erreur opérateur'],
            'RES.V.machine_fault' => ['Anomalia macchina', 'Machine fault', 'Maschinenstörung', 'Anomalie machine'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = match ($locale) {
            'it' => 0,
            'de' => 2,
            'fr' => 3,
            default => 1,
        };

        return array_map(static fn (array $values): string => $values[$index], self::definitions());
    }
}
