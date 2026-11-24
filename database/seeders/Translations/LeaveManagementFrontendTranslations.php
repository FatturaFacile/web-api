<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings for Mod 074 Leave, Permits & Absences Management (#174) across it, en, de, fr.
 */
final class LeaveManagementFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.LEAVE_MANAGEMENT' => ['Ferie, Permessi & Assenze', 'Leave, Permits & Absences', 'Ferien & Absenzen', 'Congés & Absences'],
            'NAV.LEAVE_MANAGEMENT_GANTT' => ['Calendario di Reparto (Gantt)', 'Department Gantt Calendar', 'Abteilungskalender (Gantt)', 'Calendrier Département (Gantt)'],
            'NAV.LEAVE_MANAGEMENT_LEDGER' => ['Estratto Conto Ratei', 'Accrual Ledger', 'Ferienguthaben-Konto', 'Relevé des Soldes'],
            'NAV.LEAVE_MANAGEMENT_REQUESTS' => ['Richieste, PUC & SUVA/INAIL', 'Requests, PUC & SUVA/INAIL', 'Anträge, PUC & SUVA/INAIL', 'Demandes, PUC & SUVA/INAIL'],
            'NAV.LEAVE_MANAGEMENT_COMPORTO' => ['Comporto & Art. 329b CO', 'Comporto & Art. 329b CO', 'Comporto & Art. 329b OR', 'Comporto & Art. 329b CO'],
            'NAV.LEAVE_MANAGEMENT_HOLIDAYS' => ['Festività CH/IT & Patroni', 'CH/IT Holidays & Saints', 'Feiertage CH/IT & Patrone', 'Jours fériés CH/IT & Saints'],
            'NAV.LEAVE_MANAGEMENT_POLICIES' => ['Regole di Maturazione', 'Entitlement Policies', 'Anspruchsrichtlinien', 'Règles d\'Acquisition'],
            'PAGE_TITLE.LEAVE_MANAGEMENT' => ['Gestione Ferie, Permessi e Assenze (Mod 074)', 'Leave, Permits & Absences Management (Mod 074)', 'Ferien- und Absenzenverwaltung (Mod 074)', 'Gestion Congés et Absences (Mod 074)'],

            'leaveMgmt.title' => ['Ferie, Permessi e Assenze (CH / IT)', 'Leave, Permits & Absences (CH / IT)', 'Ferien, Bewilligungen & Absenzen (CH / IT)', 'Congés, Permis & Absences (CH / IT)'],
            'leaveMgmt.subtitle' => ['Calendario Gantt di reparto, ratei Ferie/ROL/Ex-Festività/Art. 329a CO, protocollo INPS PUC, infortuni SUVA/INAIL e comporto', 'Department Gantt calendar, Ferie/ROL/Ex-Festività/Art. 329a CO ledgers, INPS PUC protocol, SUVA/INAIL claims & comporto', 'Gantt-Abteilungskalender, Ferienguthaben Art. 329a OR, INPS-PUC, SUVA/INAIL-Fälle & Ferienkürzung', 'Calendrier Gantt, soldes Art. 329a CO / Ferie / ROL, protocole INPS PUC, SUVA/INAIL & réduction'],

            'leaveMgmt.tab.gantt' => ['Calendario Reparto', 'Department Gantt', 'Abteilungskalender', 'Calendrier Gantt'],
            'leaveMgmt.tab.ledgers' => ['Estratto Conto Ratei', 'Accrual Ledgers', 'Guthaben & Ansprüche', 'Relevé des Droits'],
            'leaveMgmt.tab.requests' => ['Richieste & Malattia/Infortuni', 'Requests & Sick/Accidents', 'Anträge & Krankheit/Unfall', 'Demandes & Maladie/Accidents'],
            'leaveMgmt.tab.comporto' => ['Comporto & Art. 329b CO', 'Comporto & Art. 329b CO', 'Comporto & Art. 329b OR', 'Comporto & Art. 329b CO'],
            'leaveMgmt.tab.holidays' => ['Calendari Festività', 'Holiday Calendars', 'Feiertagskalender', 'Calendriers Fériés'],
            'leaveMgmt.tab.policies' => ['Policy Maturazione', 'Entitlement Policies', 'Richtlinien', 'Politiques'],

            'leaveMgmt.country.CH' => ['Svizzera (CH — CO / LAINF / IJM)', 'Switzerland (CH — CO / UVG / KTG)', 'Schweiz (CH — OR / UVG / KTG)', 'Suisse (CH — CO / LAA / IJM)'],
            'leaveMgmt.country.IT' => ['Italia (IT — CCNL / INPS / INAIL)', 'Italy (IT — CCNL / INPS / INAIL)', 'Italien (IT — CCNL / INPS / INAIL)', 'Italie (IT — CCNL / INPS / INAIL)'],

            'leaveMgmt.type.AnnualVacation_Ferie_Ferien' => ['Ferie / Ferien (Annuali)', 'Annual Vacation (Ferie / Ferien)', 'Ferien / Jahresurlaub', 'Vacances annuelles (Ferien / Ferie)'],
            'leaveMgmt.type.IT_PermessiROL' => ['Permessi ROL (IT)', 'ROL Permits (IT)', 'ROL-Bewilligungen (IT)', 'Permis ROL (IT)'],
            'leaveMgmt.type.IT_ExFestivita' => ['Ex-Festività (IT)', 'Ex-Holidays (IT)', 'Ehemalige Feiertage (IT)', 'Ex-Jours fériés (IT)'],
            'leaveMgmt.type.SickLeave_Malattia' => ['Malattia (INPS PUC / IJM-KTG)', 'Sick Leave (INPS PUC / IJM-KTG)', 'Krankheit (INPS PUC / KTG)', 'Maladie (INPS PUC / IJM)'],
            'leaveMgmt.type.Accident_Infortunio_SUVA_INAIL' => ['Infortunio (SUVA / LAINF / INAIL)', 'Accident (SUVA / UVG / INAIL)', 'Unfall (SUVA / UVG / INAIL)', 'Accident (SUVA / LAA / INAIL)'],
            'leaveMgmt.type.MaternityPaternity_Congedo' => ['Maternità / Paternità / Congedo', 'Maternity / Paternity Leave', 'Mutterschaft / Vaterschaft', 'Maternité / Paternité'],
            'leaveMgmt.type.IT_Legge104_Permessi' => ['Permessi Legge 104/1992 (IT)', 'Law 104/1992 Permits (IT)', 'Gesetz 104/1992 (IT)', 'Loi 104/1992 (IT)'],
            'leaveMgmt.type.CH_MilitaryCivilService_EO_APG' => ['Servizio Militare/Civile IPG / EO (CH)', 'Military/Civil Service EO/APG (CH)', 'Militär-/Zivildienst EO (CH)', 'Service militaire/civil APG (CH)'],
            'leaveMgmt.type.PaidSpecialLeave_MarriageBereavement' => ['Congedo Straordinario (Matrimonio/Lutto)', 'Paid Special Leave (Marriage/Bereavement)', 'Bezahlter Sonderurlaub', 'Congé spécial payé'],
            'leaveMgmt.type.UnpaidLeave' => ['Aspettativa non Retribuita', 'Unpaid Leave', 'Unbezahlter Urlaub', 'Congé sans solde'],

            'leaveMgmt.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'leaveMgmt.status.pending_approval' => ['In Approvazione', 'Pending Approval', 'Genehmigung ausstehend', 'En attente'],
            'leaveMgmt.status.approved' => ['Approvata', 'Approved', 'Genehmigt', 'Approuvée'],
            'leaveMgmt.status.rejected' => ['Respinta', 'Rejected', 'Abgelehnt', 'Refusée'],
            'leaveMgmt.status.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],

            'leaveMgmt.action.newRequest' => ['Nuova Richiesta', 'New Leave Request', 'Neuer Antrag', 'Nouvelle Demande'],
            'leaveMgmt.action.registerSick' => ['Registra Malattia / Infortunio (PUC/SUVA)', 'Register Sick / Accident (PUC/SUVA)', 'Krankheit / Unfall melden (PUC/SUVA)', 'Déclarer Maladie / Accident (PUC/SUVA)'],
            'leaveMgmt.action.calculateAccruals' => ['Calcola Ratei Mensili', 'Run Monthly Accruals', 'Monatliche Ansprüche berechnen', 'Calculer les Droits Mensuels'],
            'leaveMgmt.action.configureThreshold' => ['Configura Presidio Minimo', 'Configure Min Staffing', 'Mindestbesetzung festlegen', 'Configurer Effectif Minimum'],
            'leaveMgmt.action.approve' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'leaveMgmt.action.reject' => ['Respingi', 'Reject', 'Ablehnen', 'Refuser'],
            'leaveMgmt.action.cancel' => ['Annulla', 'Cancel', 'Stornieren', 'Annuler'],
            'leaveMgmt.action.evaluate' => ['Valuta Comporto / Art. 329b', 'Evaluate Comporto / Art. 329b', 'Comporto / Art. 329b prüfen', 'Évaluer Comporto / Art. 329b'],
            'leaveMgmt.action.applyCurtailment' => ['Applica Riduzione a Saldo CH', 'Apply CH Reduction to Ledger', 'Ferienkürzung verbuchen', 'Appliquer Réduction au Solde'],
            'leaveMgmt.action.createCalendar' => ['Nuovo Calendario', 'New Calendar', 'Neuer Kalender', 'Nouveau Calendrier'],
            'leaveMgmt.action.createPolicy' => ['Nuova Politica', 'New Policy', 'Neue Richtlinie', 'Nouvelle Politique'],
            'leaveMgmt.action.loadCatalog' => ['Anteprima Festività Ufficiali', 'Preview Official Holidays', 'Offizielle Feiertage prüfen', 'Aperçu Jours Fériés Officiels'],

            'leaveMgmt.field.employee' => ['Dipendente', 'Employee', 'Mitarbeitende', 'Employé'],
            'leaveMgmt.field.department' => ['Reparto', 'Department', 'Abteilung', 'Département'],
            'leaveMgmt.field.country' => ['Giurisdizione', 'Country', 'Land', 'Pays'],
            'leaveMgmt.field.leaveType' => ['Tipologia Assenza', 'Leave Type', 'Abwesenheitsart', 'Type d\'Absence'],
            'leaveMgmt.field.startDate' => ['Data Inizio', 'Start Date', 'Startdatum', 'Date Début'],
            'leaveMgmt.field.endDate' => ['Data Fine', 'End Date', 'Enddatum', 'Date Fin'],
            'leaveMgmt.field.duration' => ['Durata (Giorno / Mezza / Ore)', 'Duration (Full / Half / Hours)', 'Dauer (Tag / Halbtag / Std.)', 'Durée (Jour / Demi / Heures)'],
            'leaveMgmt.field.puc' => ['Protocollo INPS (PUC 9 car.)', 'INPS PUC Protocol (9 chars)', 'INPS-PUC-Code (9 Zeichen)', 'Protocole INPS PUC (9 car.)'],
            'leaveMgmt.field.suvaClaim' => ['Numero Sinistro SUVA / LAINF', 'SUVA / UVG Claim Number', 'SUVA / UVG Schadennummer', 'N° Sinistre SUVA / LAA'],
            'leaveMgmt.field.inailClaim' => ['Numero Pratica INAIL', 'INAIL Claim Number', 'INAIL-Fallnummer', 'N° Dossier INAIL'],
            'leaveMgmt.field.medDocId' => ['ID Documento Certificato Medico', 'Medical Certificate Doc ID', 'Arztzeugnis-Dokument-ID', 'ID Document Certificat'],
            'leaveMgmt.field.wartefrist' => ['Wartefrist IJM / KTG (giorni)', 'IJM / KTG Waiting Period (days)', 'IJM / KTG Wartefrist (Tage)', 'Délai d\'attente IJM / KTG (j.)'],
            'leaveMgmt.field.ipgEo' => ['Codice Indennità IPG / EO', 'IPG / EO Allowance Code', 'EO / APG Entschädigungscode', 'Code Allocation APG / EO'],
            'leaveMgmt.field.legge104Ref' => ['Rif. Assistito Legge 104/92', 'Legge 104/92 Beneficiary Ref', 'Gesetz 104/92 Bezugsperson', 'Réf. Bénéficiaire Loi 104/92'],
            'leaveMgmt.field.canton' => ['Cantone CH / Provincia IT', 'Swiss Canton / IT Province', 'CH Kanton / IT Provinz', 'Canton CH / Province IT'],
            'leaveMgmt.field.municipality' => ['Comune (Santo Patrono)', 'Municipality (Patron Saint)', 'Gemeinde (Schutzpatron)', 'Commune (Saint Patron)'],
            'leaveMgmt.field.patronDate' => ['Data Patrono (MM-GG)', 'Patron Saint Date (MM-DD)', 'Patrondatum (MM-TT)', 'Date Saint Patron (MM-JJ)'],
            'leaveMgmt.field.minPresentCount' => ['Minimo Presenti (#)', 'Min Present Headcount', 'Min. Anwesende (Anzahl)', 'Effectif Min. Présent'],
            'leaveMgmt.field.minPresentPercent' => ['Minimo Presidio (%)', 'Min Staffing (%)', 'Mindestbesetzung (%)', 'Présence Min. (%)'],
            'leaveMgmt.field.hardBlock' => ['Blocco Rigido Richieste', 'Enforce Hard Block', 'Harte Sperre erzwingen', 'Blocage Strict'],

            'leaveMgmt.ledger.carryOver' => ['Residuo AP', 'Prior Carry-Over', 'Übertrag Vorjahr', 'Report N-1'],
            'leaveMgmt.ledger.accruedYtd' => ['Maturato YTD', 'Accrued YTD', 'Erworben YTD', 'Acquis YTD'],
            'leaveMgmt.ledger.curtailment' => ['Rid. Art. 329b', 'Art. 329b Red.', 'Kürzung 329b', 'Réd. Art. 329b'],
            'leaveMgmt.ledger.takenYtd' => ['Goduto YTD', 'Taken YTD', 'Bezogen YTD', 'Pris YTD'],
            'leaveMgmt.ledger.plannedFuture' => ['Pianificato Fut.', 'Planned Future', 'Geplant Zukunft', 'Planifié Futur'],
            'leaveMgmt.ledger.pending' => ['In Attesa', 'Pending', 'Ausstehend', 'En Attente'],
            'leaveMgmt.ledger.projected' => ['Saldo Fine Anno', 'Projected Balance', 'Prognose Jahresende', 'Solde Projeté'],
            'leaveMgmt.ledger.deadline' => ['Scadenza 18 Mesi', '18-Mo Deadline', '18-Monats-Frist', 'Échéance 18 Mois'],

            'leaveMgmt.gantt.presentRow' => ['Presidio Reparto (Presenti / Minimo)', 'Department Coverage (Present / Min)', 'Abteilungsbesetzung (Anwesend / Min.)', 'Couverture Département (Présents / Min.)'],
            'leaveMgmt.gantt.sundayHoliday' => ['Festività Domenicale', 'Sunday Holiday', 'Sonntagsfeiertag', 'Férié Dimanche'],
            'leaveMgmt.gantt.breachAlert' => ['Allerta Presidio Minimo: copertura sotto soglia nei giorni evidenziati.', 'Minimum Staffing Alert: coverage falls below threshold on highlighted days.', 'Mindestbesetzung unterschritten an markierten Tagen.', 'Alerte Effectif Minimum : couverture insuffisante sur les jours signalés.'],

            'leaveMgmt.comporto.title' => ['Monitor Periodo di Comporto (Italia)', 'Periodo di Comporto Monitor (Italy)', 'Comporto-Überwachung (Italien)', 'Suivi Période de Comporto (Italie)'],
            'leaveMgmt.curtailment.title' => ['Riduzione Vacanze Art. 329b CO (Svizzera)', 'Art. 329b CO Vacation Curtailment (Switzerland)', 'Ferienkürzung Art. 329b OR (Schweiz)', 'Réduction Vacances Art. 329b CO (Suisse)'],
            'leaveMgmt.payrollFeed.title' => ['Anteprima Integrazione Paghe (Mod 076 CH / Mod 077 IT)', 'Payroll Integration Feed (Mod 076 CH / Mod 077 IT)', 'Lohnbuchhaltungs-Export (Mod 076 CH / Mod 077 IT)', 'Flux Paie (Mod 076 CH / Mod 077 IT)'],
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
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        $resolved = [];
        foreach (self::definitions() as $key => $tuple) {
            $resolved[$key] = $tuple[$index];
        }

        return $resolved;
    }
}
