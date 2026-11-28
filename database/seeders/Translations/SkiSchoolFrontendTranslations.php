<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the ski school, alpine guide and outdoor centre module (Mod 286, Issue #532): menus, page titles, the planning board with the
 * fair rotation ranking, the weekly group courses and the level board, the package builder with the IT / CH VAT exemption, the instructor
 * roster, the payout statements, the public booking stepper, enumerations (disciplines, languages, levels, statuses, licences, regimes),
 * rotation reasons and the codes the API reports (skiSchool.error.<code>). Each entry is [it, en, de, fr]; the Italian text serves it-CH and
 * it-IT, the German text de-CH and the French text fr-CH. Placeholders are {name}. Legal acronyms and procedure names (IVA, LIVA, AVS, UIAGM,
 * IFMGA, AMSI, FISI, ARTVA, SDI) stay untranslated.
 */
final class SkiSchoolFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::levels(),
            self::reasons(),
            self::errors(),
            self::fields(),
            self::columns(),
            self::actions(),
            self::sections(),
            self::messages(),
            self::labels(),
            self::publicPortal(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.SKI_SCHOOL' => ['Scuole sci e outdoor (Mod 286)', 'Ski schools & outdoor (Mod 286)', 'Skischulen & Outdoor (Mod 286)', 'Écoles de ski & outdoor (Mod 286)'],
            'NAV.SKI_SCHOOL_DASHBOARD' => ['Panoramica scuola', 'School overview', 'Schulübersicht', 'Vue d\'ensemble de l\'école'],
            'NAV.SKI_SCHOOL_PLANNING' => ['Planning maestri', 'Instructor planning', 'Lehrerplanung', 'Planning des moniteurs'],
            'NAV.SKI_SCHOOL_COURSES' => ['Corsi collettivi e livelli', 'Group courses and levels', 'Gruppenkurse und Stufen', 'Cours collectifs et niveaux'],
            'NAV.SKI_SCHOOL_INSTRUCTORS' => ['Maestri e guide', 'Instructors and guides', 'Lehrer und Führer', 'Moniteurs et guides'],
            'NAV.SKI_SCHOOL_PAYOUTS' => ['Compensi maestri', 'Instructor payouts', 'Lehrerhonorare', 'Rémunérations des moniteurs'],
            'PAGE_TITLE.SKI_SCHOOL_DASHBOARD' => ['Scuola di sci e centro outdoor: panoramica', 'Ski school and outdoor centre: overview', 'Skischule und Outdoor-Zentrum: Übersicht', 'École de ski et centre outdoor : vue d\'ensemble'],
            'PAGE_TITLE.SKI_SCHOOL_PLANNING' => ['Planning multi-maestro', 'Multi-instructor planning', 'Mehrlehrer-Planung', 'Planning multi-moniteurs'],
            'PAGE_TITLE.SKI_SCHOOL_COURSES' => ['Corsi collettivi settimanali', 'Weekly group courses', 'Wöchentliche Gruppenkurse', 'Cours collectifs hebdomadaires'],
            'PAGE_TITLE.SKI_SCHOOL_INSTRUCTORS' => ['Anagrafica maestri e guide', 'Instructor and guide roster', 'Lehrer- und Führerverzeichnis', 'Registre des moniteurs et guides'],
            'PAGE_TITLE.SKI_SCHOOL_PAYOUTS' => ['Estratto conto compensi maestri', 'Instructor payout statements', 'Honorarabrechnungen der Lehrer', 'Relevés de rémunération des moniteurs'],
            'PAGE_TITLE.SKI_SCHOOL_BOOKING' => ['Prenota una lezione o un corso', 'Book a lesson or a course', 'Lektion oder Kurs buchen', 'Réserver une leçon ou un cours'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'skiSchool.discipline.alpine_ski' => ['Sci alpino', 'Alpine skiing', 'Ski alpin', 'Ski alpin'],
            'skiSchool.discipline.snowboard' => ['Snowboard', 'Snowboard', 'Snowboard', 'Snowboard'],
            'skiSchool.discipline.telemark' => ['Telemark', 'Telemark', 'Telemark', 'Télémark'],
            'skiSchool.discipline.cross_country' => ['Sci di fondo', 'Cross-country skiing', 'Langlauf', 'Ski de fond'],
            'skiSchool.discipline.freeride' => ['Freeride', 'Freeride', 'Freeride', 'Freeride'],
            'skiSchool.discipline.alpine_guide' => ['Guida alpina', 'Alpine guide', 'Bergführer', 'Guide de montagne'],
            'skiSchool.discipline.outdoor_rafting' => ['Rafting', 'Rafting', 'Rafting', 'Rafting'],
            'skiSchool.discipline.outdoor_canyoning' => ['Canyoning', 'Canyoning', 'Canyoning', 'Canyoning'],
            'skiSchool.discipline.outdoor_paragliding' => ['Parapendio', 'Paragliding', 'Gleitschirmfliegen', 'Parapente'],
            'skiSchool.discipline.outdoor_mtb' => ['Mountain bike', 'Mountain bike', 'Mountainbike', 'VTT'],
            'skiSchool.lang.IT' => ['Italiano', 'Italian', 'Italienisch', 'Italien'],
            'skiSchool.lang.DE' => ['Tedesco', 'German', 'Deutsch', 'Allemand'],
            'skiSchool.lang.FR' => ['Francese', 'French', 'Französisch', 'Français'],
            'skiSchool.lang.EN' => ['Inglese', 'English', 'Englisch', 'Anglais'],
            'skiSchool.licenseType.SWISS_SNOWSPORTS' => ['Swiss Snowsports', 'Swiss Snowsports', 'Swiss Snowsports', 'Swiss Snowsports'],
            'skiSchool.licenseType.UIAGM_IFMGA' => ['Guida UIAGM / IFMGA', 'UIAGM / IFMGA guide', 'UIAGM / IFMGA Bergführer', 'Guide UIAGM / IFMGA'],
            'skiSchool.licenseType.AMSI_FISI' => ['Maestro AMSI / FISI', 'AMSI / FISI instructor', 'AMSI / FISI Lehrer', 'Moniteur AMSI / FISI'],
            'skiSchool.licenseType.OTHER' => ['Altra qualifica', 'Other qualification', 'Andere Qualifikation', 'Autre qualification'],
            'skiSchool.retentionMode.PERCENT' => ['Percentuale sul compenso', 'Percentage of the fee', 'Prozent des Honorars', 'Pourcentage de la rémunération'],
            'skiSchool.retentionMode.FIXED_HOURLY' => ['Quota fissa oraria', 'Fixed hourly fee', 'Fester Stundenbetrag', 'Montant horaire fixe'],
            'skiSchool.retentionMode.NONE' => ['Nessuna trattenuta', 'No retention', 'Kein Abzug', 'Aucune retenue'],
            'skiSchool.regime.FREELANCE' => ['Libero professionista (partita IVA)', 'Freelancer (VAT registered)', 'Selbständig (MWST-pflichtig)', 'Indépendant (assujetti à la TVA)'],
            'skiSchool.regime.OCCASIONAL' => ['Prestazione occasionale', 'Occasional service', 'Gelegentliche Leistung', 'Prestation occasionnelle'],
            'skiSchool.regime.SPORT_COLLABORATION' => ['Collaborazione sportiva dilettantistica', 'Amateur sports collaboration', 'Amateursport-Mitarbeit', 'Collaboration sportive amateur'],
            'skiSchool.regime.EMPLOYEE' => ['Lavoratore dipendente', 'Employee', 'Angestellter', 'Salarié'],
            'skiSchool.regime.SWISS_INDEPENDENT' => ['Indipendente svizzero (AVS)', 'Swiss self-employed (AVS)', 'Selbständig in der Schweiz (AHV)', 'Indépendant suisse (AVS)'],
            'skiSchool.regimeNote.FREELANCE' => ['Il maestro fattura il compenso con la propria partita IVA: l\'estratto conto non è un documento fiscale.', 'The instructor invoices the fee with their own VAT number: the statement is not a tax document.', 'Der Lehrer stellt das Honorar mit eigener MWST-Nummer in Rechnung: Die Abrechnung ist kein Steuerbeleg.', 'Le moniteur facture la rémunération avec son propre numéro de TVA : le relevé n\'est pas un document fiscal.'],
            'skiSchool.regimeNote.OCCASIONAL' => ['Prestazione occasionale: applicare la ritenuta d\'acconto indicata e rilasciare la certificazione unica.', 'Occasional service: apply the withholding shown and issue the annual withholding certificate.', 'Gelegentliche Leistung: den angegebenen Quellenabzug anwenden und die Jahresbescheinigung ausstellen.', 'Prestation occasionnelle : appliquer la retenue indiquée et délivrer l\'attestation annuelle.'],
            'skiSchool.regimeNote.SPORT_COLLABORATION' => ['Collaborazione sportiva dilettantistica: verificare le soglie di esenzione e la comunicazione al registro delle attività sportive.', 'Amateur sports collaboration: check the exemption thresholds and the communication to the sports activities register.', 'Amateursport-Mitarbeit: Freigrenzen und Meldung an das Sportregister prüfen.', 'Collaboration sportive amateur : vérifier les seuils d\'exonération et la déclaration au registre sportif.'],
            'skiSchool.regimeNote.EMPLOYEE' => ['Lavoratore dipendente: il compenso confluisce nel cedolino paga, questo estratto è solo un riepilogo ore.', 'Employee: the fee goes through payroll, this statement is only a summary of hours.', 'Angestellter: Das Honorar läuft über die Lohnabrechnung, diese Abrechnung ist nur eine Stundenübersicht.', 'Salarié : la rémunération passe par la paie, ce relevé n\'est qu\'un récapitulatif d\'heures.'],
            'skiSchool.regimeNote.SWISS_INDEPENDENT' => ['Indipendente svizzero: il maestro risponde dei contributi AVS in proprio; la scuola non effettua trattenute.', 'Swiss self-employed: the instructor pays their own AVS contributions; the school withholds nothing.', 'Selbständig in der Schweiz: Der Lehrer entrichtet die AHV-Beiträge selbst; die Schule behält nichts ein.', 'Indépendant suisse : le moniteur règle lui-même ses cotisations AVS ; l\'école ne retient rien.'],
            'skiSchool.lessonStatus.REQUESTED' => ['Richiesta', 'Requested', 'Angefragt', 'Demandée'],
            'skiSchool.lessonStatus.CONFIRMED' => ['Confermata', 'Confirmed', 'Bestätigt', 'Confirmée'],
            'skiSchool.lessonStatus.COMPLETED' => ['Svolta', 'Completed', 'Durchgeführt', 'Effectuée'],
            'skiSchool.lessonStatus.CANCELLED' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'skiSchool.lessonStatus.NO_SHOW' => ['Cliente assente', 'No-show', 'Nicht erschienen', 'Client absent'],
            'skiSchool.assignmentMethod.PENDING' => ['Da assegnare', 'To be assigned', 'Zuzuweisen', 'À attribuer'],
            'skiSchool.assignmentMethod.NOMINATED' => ['Maestro richiesto', 'Requested instructor', 'Gewünschter Lehrer', 'Moniteur demandé'],
            'skiSchool.assignmentMethod.FAIR_ROTATION' => ['Rotazione equa', 'Fair rotation', 'Faire Rotation', 'Rotation équitable'],
            'skiSchool.assignmentMethod.MANUAL' => ['Assegnazione manuale', 'Manual assignment', 'Manuelle Zuweisung', 'Attribution manuelle'],
            'skiSchool.courseStatus.PLANNED' => ['Pianificato', 'Planned', 'Geplant', 'Planifié'],
            'skiSchool.courseStatus.OPEN' => ['Iscrizioni aperte', 'Open for enrolment', 'Anmeldung offen', 'Inscriptions ouvertes'],
            'skiSchool.courseStatus.RUNNING' => ['In corso', 'Running', 'Laufend', 'En cours'],
            'skiSchool.courseStatus.COMPLETED' => ['Concluso', 'Completed', 'Abgeschlossen', 'Terminé'],
            'skiSchool.courseStatus.CANCELLED' => ['Annullato', 'Cancelled', 'Abgesagt', 'Annulé'],
            'skiSchool.payoutStatus.DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'skiSchool.payoutStatus.ISSUED' => ['Emesso', 'Issued', 'Ausgestellt', 'Émis'],
            'skiSchool.payoutStatus.PAID' => ['Pagato', 'Paid', 'Bezahlt', 'Payé'],
            'skiSchool.itemType.RENTAL_SKI_KIT' => ['Noleggio sci e scarponi', 'Ski and boots rental', 'Miete Ski und Schuhe', 'Location skis et chaussures'],
            'skiSchool.itemType.RENTAL_SNOWBOARD_KIT' => ['Noleggio snowboard e scarponi', 'Snowboard and boots rental', 'Miete Snowboard und Schuhe', 'Location snowboard et chaussures'],
            'skiSchool.itemType.RENTAL_BOOTS' => ['Noleggio scarponi', 'Boots rental', 'Miete Schuhe', 'Location chaussures'],
            'skiSchool.itemType.RENTAL_HELMET' => ['Noleggio casco', 'Helmet rental', 'Miete Helm', 'Location casque'],
            'skiSchool.itemType.ARTVA_KIT' => ['Kit ARTVA (pala, sonda)', 'ARTVA kit (shovel, probe)', 'ARTVA-Set (Schaufel, Sonde)', 'Kit ARTVA (pelle, sonde)'],
            'skiSchool.itemType.SKIPASS' => ['Skipass', 'Skipass', 'Skipass', 'Forfait'],
            'skiSchool.itemType.OTHER' => ['Altro', 'Other', 'Andere', 'Autre'],
            'skiSchool.itemStatus.RESERVED' => ['Prenotato', 'Reserved', 'Reserviert', 'Réservé'],
            'skiSchool.itemStatus.DELIVERED' => ['Consegnato', 'Handed over', 'Übergeben', 'Remis'],
            'skiSchool.itemStatus.RETURNED' => ['Restituito', 'Returned', 'Zurückgegeben', 'Rendu'],
            'skiSchool.itemStatus.CANCELLED' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],
            'skiSchool.vatRegime.IT_EXEMPT_ART10_N20' => ['Esente IVA Art. 10 n. 20 DPR 633/72', 'VAT exempt Art. 10 n. 20 DPR 633/72', 'MWST-befreit Art. 10 Nr. 20 DPR 633/72', 'Exonéré de TVA Art. 10 n. 20 DPR 633/72'],
            'skiSchool.vatRegime.CH_EXEMPT_ART21_N11' => ['Esente IVA Art. 21 cpv. 2 n. 11 LIVA', 'VAT exempt Art. 21 para. 2 no. 11 LIVA', 'MWST-befreit Art. 21 Abs. 2 Ziff. 11 LIVA', 'Exonéré de TVA Art. 21 al. 2 ch. 11 LIVA'],
            'skiSchool.vatRegime.TAXABLE' => ['Imponibile', 'Taxable', 'Steuerpflichtig', 'Imposable'],
            'skiSchool.availabilityKind.AVAILABLE' => ['Disponibile', 'Available', 'Verfügbar', 'Disponible'],
            'skiSchool.availabilityKind.BLACKOUT' => ['Indisponibile', 'Blackout', 'Gesperrt', 'Indisponible'],
            'skiSchool.commissionSource.LESSON' => ['Lezione privata', 'Private lesson', 'Privatlektion', 'Leçon privée'],
            'skiSchool.commissionSource.COURSE' => ['Corso collettivo', 'Group course', 'Gruppenkurs', 'Cours collectif'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function levels(): array
    {
        return [
            'skiSchool.level.SNOW_LEAGUE.SNOWLI' => ['Snowli', 'Snowli', 'Snowli', 'Snowli'],
            'skiSchool.level.SNOW_LEAGUE.BLUE_PRINCE' => ['Blue Prince', 'Blue Prince', 'Blue Prince', 'Blue Prince'],
            'skiSchool.level.SNOW_LEAGUE.BLUE_KING' => ['Blue King', 'Blue King', 'Blue King', 'Blue King'],
            'skiSchool.level.SNOW_LEAGUE.BLUE_STAR' => ['Blue Star', 'Blue Star', 'Blue Star', 'Blue Star'],
            'skiSchool.level.SNOW_LEAGUE.RED_PRINCE' => ['Red Prince', 'Red Prince', 'Red Prince', 'Red Prince'],
            'skiSchool.level.SNOW_LEAGUE.RED_KING' => ['Red King', 'Red King', 'Red King', 'Red King'],
            'skiSchool.level.SNOW_LEAGUE.RED_STAR' => ['Red Star', 'Red Star', 'Red Star', 'Red Star'],
            'skiSchool.level.SNOW_LEAGUE.BLACK_PRINCE' => ['Black Prince', 'Black Prince', 'Black Prince', 'Black Prince'],
            'skiSchool.level.SNOW_LEAGUE.BLACK_KING' => ['Black King', 'Black King', 'Black King', 'Black King'],
            'skiSchool.level.SNOW_LEAGUE.BLACK_STAR' => ['Black Star', 'Black Star', 'Black Star', 'Black Star'],
            'skiSchool.level.FISI_AMSI.BRONZE' => ['Bronzo', 'Bronze', 'Bronze', 'Bronze'],
            'skiSchool.level.FISI_AMSI.SILVER' => ['Argento', 'Silver', 'Silber', 'Argent'],
            'skiSchool.level.FISI_AMSI.GOLD' => ['Oro', 'Gold', 'Gold', 'Or'],
        ];
    }

    /**
     * Why an instructor cannot take a slot (fair rotation ranking and board).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function reasons(): array
    {
        return [
            'skiSchool.reason.discipline_mismatch' => ['Non insegna questa disciplina', 'Does not teach this discipline', 'Unterrichtet diese Disziplin nicht', 'N\'enseigne pas cette discipline'],
            'skiSchool.reason.language_mismatch' => ['Non parla questa lingua', 'Does not speak this language', 'Spricht diese Sprache nicht', 'Ne parle pas cette langue'],
            'skiSchool.reason.blackout' => ['Indisponibilità inserita', 'Blackout entered', 'Sperre eingetragen', 'Indisponibilité saisie'],
            'skiSchool.reason.outside_availability' => ['Fuori dalla disponibilità', 'Outside the availability window', 'Ausserhalb der Verfügbarkeit', 'Hors de la disponibilité'],
            'skiSchool.reason.lesson_conflict' => ['Ha già una lezione in quell\'orario', 'Already has a lesson at that time', 'Hat zu dieser Zeit bereits eine Lektion', 'A déjà une leçon à cette heure'],
            'skiSchool.reason.course_conflict' => ['Impegnato in un corso collettivo', 'Busy with a group course', 'Durch einen Gruppenkurs belegt', 'Occupé par un cours collectif'],
            'skiSchool.reason.unavailable' => ['Non disponibile', 'Not available', 'Nicht verfügbar', 'Non disponible'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'skiSchool.error.unknown' => ['Operazione non riuscita.', 'The operation failed.', 'Der Vorgang ist fehlgeschlagen.', 'L\'opération a échoué.'],
            'skiSchool.error.availability_not_found' => ['Disponibilità non trovata.', 'Availability not found.', 'Verfügbarkeit nicht gefunden.', 'Disponibilité introuvable.'],
            'skiSchool.error.capacity_below_enrolled' => ['La capienza è inferiore agli iscritti ({enrolled}).', 'The capacity is below the number of enrolled students ({enrolled}).', 'Die Kapazität liegt unter der Zahl der Angemeldeten ({enrolled}).', 'La capacité est inférieure au nombre d\'inscrits ({enrolled}).'],
            'skiSchool.error.capacity_exceeded' => ['Il gruppo {group} supererebbe la capienza di {capacity} posti.', 'Group {group} would exceed its capacity of {capacity} places.', 'Die Gruppe {group} würde ihre Kapazität von {capacity} Plätzen überschreiten.', 'Le groupe {group} dépasserait sa capacité de {capacity} places.'],
            'skiSchool.error.course_closed' => ['Il corso è {status}: le iscrizioni sono chiuse.', 'The course is {status}: enrolment is closed.', 'Der Kurs ist {status}: Die Anmeldung ist geschlossen.', 'Le cours est {status} : les inscriptions sont closes.'],
            'skiSchool.error.course_full' => ['Il corso è al completo ({capacity} posti).', 'The course is full ({capacity} places).', 'Der Kurs ist ausgebucht ({capacity} Plätze).', 'Le cours est complet ({capacity} places).'],
            'skiSchool.error.course_locked' => ['Il corso è {status} e non si può più modificare.', 'The course is {status} and can no longer be changed.', 'Der Kurs ist {status} und kann nicht mehr geändert werden.', 'Le cours est {status} et ne peut plus être modifié.'],
            'skiSchool.error.course_not_found' => ['Corso non trovato.', 'Course not found.', 'Kurs nicht gefunden.', 'Cours introuvable.'],
            'skiSchool.error.customer_contact_required' => ['Scegli il cliente a cui intestare la fattura.', 'Pick the customer the invoice is addressed to.', 'Wählen Sie den Kunden für die Rechnung.', 'Choisissez le client destinataire de la facture.'],
            'skiSchool.error.date_outside_course' => ['La data è fuori dal periodo del corso.', 'The date is outside the course period.', 'Das Datum liegt ausserhalb des Kurszeitraums.', 'La date est hors de la période du cours.'],
            'skiSchool.error.duplicate_move' => ['Lo stesso allievo è nel trasferimento più di una volta.', 'The same student appears more than once in the transfer.', 'Derselbe Schüler kommt mehrfach in der Übertragung vor.', 'Le même élève figure plusieurs fois dans le transfert.'],
            'skiSchool.error.enrollment_invoiced' => ['L\'iscrizione è già fatturata.', 'The enrolment is already invoiced.', 'Die Anmeldung ist bereits fakturiert.', 'L\'inscription est déjà facturée.'],
            'skiSchool.error.enrollment_not_active' => ['{student} non è più iscritto.', '{student} is no longer enrolled.', '{student} ist nicht mehr angemeldet.', '{student} n\'est plus inscrit.'],
            'skiSchool.error.enrollment_not_billable' => ['Un\'iscrizione {status} non si può fatturare.', 'An enrolment in status {status} cannot be invoiced.', 'Eine Anmeldung im Status {status} kann nicht fakturiert werden.', 'Une inscription au statut {status} ne peut pas être facturée.'],
            'skiSchool.error.enrollment_not_found' => ['Iscrizione non trovata.', 'Enrolment not found.', 'Anmeldung nicht gefunden.', 'Inscription introuvable.'],
            'skiSchool.error.group_closed' => ['Il gruppo {group} non accetta più allievi.', 'Group {group} no longer takes students.', 'Die Gruppe {group} nimmt keine Schüler mehr auf.', 'Le groupe {group} n\'accepte plus d\'élèves.'],
            'skiSchool.error.group_mismatch' => ['I gruppi {from} e {to} non hanno la stessa disciplina e settimana.', 'Groups {from} and {to} do not share discipline and week.', 'Die Gruppen {from} und {to} haben nicht dieselbe Disziplin und Woche.', 'Les groupes {from} et {to} n\'ont pas la même discipline et semaine.'],
            'skiSchool.error.group_not_found' => ['Gruppo non trovato.', 'Group not found.', 'Gruppe nicht gefunden.', 'Groupe introuvable.'],
            'skiSchool.error.instructor_conflict' => ['{instructor} non è libero in quell\'orario ({reason}).', '{instructor} is not free at that time ({reason}).', '{instructor} ist zu dieser Zeit nicht frei ({reason}).', '{instructor} n\'est pas libre à cette heure ({reason}).'],
            'skiSchool.error.instructor_course_conflict' => ['Il maestro ha già un corso con orario sovrapposto.', 'The instructor already has a course at an overlapping time.', 'Der Lehrer hat bereits einen Kurs zu überschneidender Zeit.', 'Le moniteur a déjà un cours à un horaire qui se chevauche.'],
            'skiSchool.error.instructor_in_use' => ['Il maestro ha lezioni o corsi aperti.', 'The instructor has open lessons or courses.', 'Der Lehrer hat offene Lektionen oder Kurse.', 'Le moniteur a des leçons ou cours ouverts.'],
            'skiSchool.error.instructor_not_found' => ['Maestro non trovato o non attivo.', 'Instructor not found or not active.', 'Lehrer nicht gefunden oder nicht aktiv.', 'Moniteur introuvable ou inactif.'],
            'skiSchool.error.instructor_not_qualified' => ['{instructor} non insegna questa disciplina o non parla questa lingua.', '{instructor} does not teach this discipline or speak this language.', '{instructor} unterrichtet diese Disziplin nicht oder spricht diese Sprache nicht.', '{instructor} n\'enseigne pas cette discipline ou ne parle pas cette langue.'],
            'skiSchool.error.invalid_discipline' => ['Disciplina non valida.', 'Invalid discipline.', 'Ungültige Disziplin.', 'Discipline invalide.'],
            'skiSchool.error.invalid_item_transition' => ['Un articolo {from} non può passare a {to}.', 'An item in status {from} cannot become {to}.', 'Ein Artikel im Status {from} kann nicht zu {to} werden.', 'Un article au statut {from} ne peut pas passer à {to}.'],
            'skiSchool.error.invalid_item_type' => ['Tipo di articolo non valido.', 'Invalid item type.', 'Ungültige Artikelart.', 'Type d\'article invalide.'],
            'skiSchool.error.invalid_period' => ['Il periodo del corso non è valido (massimo 14 giorni).', 'The course period is not valid (14 days at most).', 'Der Kurszeitraum ist ungültig (höchstens 14 Tage).', 'La période du cours est invalide (14 jours au maximum).'],
            'skiSchool.error.invalid_slot' => ['Durata o numero di slot non validi.', 'Invalid slot length or slot count.', 'Ungültige Slotdauer oder Slotanzahl.', 'Durée ou nombre de créneaux invalide.'],
            'skiSchool.error.invalid_statement_transition' => ['Un estratto {from} non può passare a {to}.', 'A statement in status {from} cannot become {to}.', 'Eine Abrechnung im Status {from} kann nicht zu {to} werden.', 'Un relevé au statut {from} ne peut pas passer à {to}.'],
            'skiSchool.error.invalid_status_transition' => ['Cambio di stato non consentito.', 'Status change not allowed.', 'Statuswechsel nicht erlaubt.', 'Changement de statut non autorisé.'],
            'skiSchool.error.item_not_found' => ['Articolo non trovato.', 'Item not found.', 'Artikel nicht gefunden.', 'Article introuvable.'],
            'skiSchool.error.lesson_invoiced' => ['La lezione è già fatturata.', 'The lesson is already invoiced.', 'Die Lektion ist bereits fakturiert.', 'La leçon est déjà facturée.'],
            'skiSchool.error.lesson_not_billable' => ['Una lezione {status} non si può fatturare.', 'A lesson in status {status} cannot be invoiced.', 'Eine Lektion im Status {status} kann nicht fakturiert werden.', 'Une leçon au statut {status} ne peut pas être facturée.'],
            'skiSchool.error.lesson_not_found' => ['Lezione non trovata.', 'Lesson not found.', 'Lektion nicht gefunden.', 'Leçon introuvable.'],
            'skiSchool.error.lesson_not_open' => ['La lezione è {status} e non si può più modificare.', 'The lesson is {status} and can no longer be changed.', 'Die Lektion ist {status} und kann nicht mehr geändert werden.', 'La leçon est {status} et ne peut plus être modifiée.'],
            'skiSchool.error.lesson_without_instructor' => ['Assegna un maestro prima di chiudere la lezione.', 'Assign an instructor before completing the lesson.', 'Weisen Sie einen Lehrer zu, bevor Sie die Lektion abschliessen.', 'Attribuez un moniteur avant de clôturer la leçon.'],
            'skiSchool.error.level_not_found' => ['Livello non trovato.', 'Level not found.', 'Stufe nicht gefunden.', 'Niveau introuvable.'],
            'skiSchool.error.no_instructor_available' => ['Nessun maestro disponibile per questa disciplina, lingua e orario.', 'No instructor is available for this discipline, language and time.', 'Kein Lehrer ist für diese Disziplin, Sprache und Zeit verfügbar.', 'Aucun moniteur n\'est disponible pour cette discipline, langue et heure.'],
            'skiSchool.error.nothing_to_pay' => ['Nessun compenso da liquidare in questo mese.', 'There is nothing to pay out this month.', 'In diesem Monat ist nichts auszuzahlen.', 'Rien à rémunérer ce mois-ci.'],
            'skiSchool.error.owner_invoiced' => ['Il documento è già fatturato: il pacchetto non si può modificare.', 'The document is already invoiced: the package cannot be changed.', 'Das Dokument ist bereits fakturiert: Das Paket kann nicht geändert werden.', 'Le document est déjà facturé : le forfait ne peut plus être modifié.'],
            'skiSchool.error.requested_instructor_unavailable' => ['Il maestro richiesto non è disponibile ({reasons}).', 'The requested instructor is not available ({reasons}).', 'Der gewünschte Lehrer ist nicht verfügbar ({reasons}).', 'Le moniteur demandé n\'est pas disponible ({reasons}).'],
            'skiSchool.error.same_group' => ['L\'allievo è già in quel gruppo.', 'The student is already in that group.', 'Der Schüler ist bereits in dieser Gruppe.', 'L\'élève est déjà dans ce groupe.'],
            'skiSchool.error.statement_locked' => ['L\'estratto del mese è {status} e non si può rigenerare.', 'The statement of the month is {status} and cannot be regenerated.', 'Die Abrechnung des Monats ist {status} und kann nicht neu erstellt werden.', 'Le relevé du mois est {status} et ne peut pas être régénéré.'],
            'skiSchool.error.statement_not_found' => ['Estratto conto non trovato.', 'Statement not found.', 'Abrechnung nicht gefunden.', 'Relevé introuvable.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'skiSchool.field.autoAssign' => ['Assegna con rotazione equa', 'Assign by fair rotation', 'Mit fairer Rotation zuweisen', 'Attribuer par rotation équitable'],
            'skiSchool.field.availabilityKind' => ['Tipo', 'Type', 'Art', 'Type'],
            'skiSchool.field.birthDate' => ['Data di nascita', 'Date of birth', 'Geburtsdatum', 'Date de naissance'],
            'skiSchool.field.bookingEnabled' => ['Prenotazione online attiva', 'Online booking enabled', 'Online-Buchung aktiv', 'Réservation en ligne active'],
            'skiSchool.field.capacity' => ['Capienza', 'Capacity', 'Kapazität', 'Capacité'],
            'skiSchool.field.collaborationRegime' => ['Regime di collaborazione', 'Collaboration regime', 'Zusammenarbeitsregime', 'Régime de collaboration'],
            'skiSchool.field.customerEmail' => ['Email', 'Email', 'E-Mail', 'E-mail'],
            'skiSchool.field.customerName' => ['Nome del cliente', 'Customer name', 'Name des Kunden', 'Nom du client'],
            'skiSchool.field.customerPhone' => ['Telefono', 'Phone', 'Telefon', 'Téléphone'],
            'skiSchool.field.dailyStartTime' => ['Inizio giornaliero', 'Daily start time', 'Tagesbeginn', 'Début quotidien'],
            'skiSchool.field.date' => ['Data', 'Date', 'Datum', 'Date'],
            'skiSchool.field.defaultRetentionMode' => ['Trattenuta di segreteria predefinita', 'Default secretariat retention', 'Standard-Sekretariatsabzug', 'Retenue de secrétariat par défaut'],
            'skiSchool.field.defaultRetentionValue' => ['Valore della trattenuta (% o CHF/EUR all\'ora)', 'Retention value (% or CHF/EUR per hour)', 'Wert des Abzugs (% oder CHF/EUR pro Stunde)', 'Valeur de la retenue (% ou CHF/EUR par heure)'],
            'skiSchool.field.discipline' => ['Disciplina', 'Discipline', 'Disziplin', 'Discipline'],
            'skiSchool.field.disciplines' => ['Discipline insegnate', 'Disciplines taught', 'Unterrichtete Disziplinen', 'Disciplines enseignées'],
            'skiSchool.field.durationMinutes' => ['Durata giornaliera (minuti)', 'Daily duration (minutes)', 'Tägliche Dauer (Minuten)', 'Durée quotidienne (minutes)'],
            'skiSchool.field.email' => ['Email', 'Email', 'E-Mail', 'E-mail'],
            'skiSchool.field.emergencyContactName' => ['Contatto di emergenza', 'Emergency contact', 'Notfallkontakt', 'Contact d\'urgence'],
            'skiSchool.field.emergencyContactPhone' => ['Telefono di emergenza', 'Emergency phone', 'Notfalltelefon', 'Téléphone d\'urgence'],
            'skiSchool.field.endDate' => ['Fine corso', 'Course end', 'Kursende', 'Fin du cours'],
            'skiSchool.field.endTime' => ['Ora di fine', 'End time', 'Endzeit', 'Heure de fin'],
            'skiSchool.field.firstName' => ['Nome', 'First name', 'Vorname', 'Prénom'],
            'skiSchool.field.guardianName' => ['Genitore / tutore', 'Parent / guardian', 'Eltern / Vormund', 'Parent / tuteur'],
            'skiSchool.field.hourlyRate' => ['Tariffa oraria del maestro', 'Instructor hourly rate', 'Stundensatz des Lehrers', 'Tarif horaire du moniteur'],
            'skiSchool.field.instructor' => ['Maestro', 'Instructor', 'Lehrer', 'Moniteur'],
            'skiSchool.field.isActive' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'skiSchool.field.jurisdiction' => ['Giurisdizione fiscale', 'Tax jurisdiction', 'Steuerliche Zuständigkeit', 'Juridiction fiscale'],
            'skiSchool.field.language' => ['Lingua', 'Language', 'Sprache', 'Langue'],
            'skiSchool.field.languages' => ['Lingue parlate', 'Spoken languages', 'Gesprochene Sprachen', 'Langues parlées'],
            'skiSchool.field.lastName' => ['Cognome', 'Last name', 'Nachname', 'Nom'],
            'skiSchool.field.level' => ['Livello tecnico', 'Technical level', 'Technische Stufe', 'Niveau technique'],
            'skiSchool.field.licenseNumber' => ['Numero tessera', 'Licence number', 'Ausweisnummer', 'Numéro de licence'],
            'skiSchool.field.licenseType' => ['Qualifica', 'Qualification', 'Qualifikation', 'Qualification'],
            'skiSchool.field.licenseValidUntil' => ['Valida fino al', 'Valid until', 'Gültig bis', 'Valable jusqu\'au'],
            'skiSchool.field.maxWeeklyHours' => ['Ore massime settimanali', 'Maximum weekly hours', 'Maximale Wochenstunden', 'Heures hebdomadaires maximales'],
            'skiSchool.field.meetingPoint' => ['Punto di ritrovo', 'Meeting point', 'Treffpunkt', 'Point de rencontre'],
            'skiSchool.field.minParticipants' => ['Minimo partecipanti', 'Minimum participants', 'Mindestteilnehmer', 'Minimum de participants'],
            'skiSchool.field.note' => ['Nota', 'Note', 'Notiz', 'Note'],
            'skiSchool.field.participants' => ['Partecipanti', 'Participants', 'Teilnehmer', 'Participants'],
            'skiSchool.field.phone' => ['Telefono', 'Phone', 'Telefon', 'Téléphone'],
            'skiSchool.field.priceNet' => ['Prezzo netto', 'Net price', 'Nettopreis', 'Prix net'],
            'skiSchool.field.publicSlug' => ['Indirizzo della pagina di prenotazione', 'Booking page address', 'Adresse der Buchungsseite', 'Adresse de la page de réservation'],
            'skiSchool.field.requestedInstructor' => ['Maestro richiesto', 'Requested instructor', 'Gewünschter Lehrer', 'Moniteur demandé'],
            'skiSchool.field.retentionMode' => ['Modalità di trattenuta', 'Retention mode', 'Abzugsart', 'Mode de retenue'],
            'skiSchool.field.retentionValue' => ['Valore (% o all\'ora)', 'Value (% or per hour)', 'Wert (% oder pro Stunde)', 'Valeur (% ou par heure)'],
            'skiSchool.field.slotCount' => ['Numero di slot consecutivi', 'Consecutive slots', 'Aufeinanderfolgende Slots', 'Créneaux consécutifs'],
            'skiSchool.field.slotMinutes' => ['Durata dello slot', 'Slot length', 'Slotdauer', 'Durée du créneau'],
            'skiSchool.field.startTime' => ['Ora di inizio', 'Start time', 'Startzeit', 'Heure de début'],
            'skiSchool.field.studentName' => ['Nome dell\'allievo', 'Student name', 'Name des Schülers', 'Nom de l\'élève'],
            'skiSchool.field.transferReason' => ['Motivo (es. valutazione del lunedì)', 'Reason (e.g. Monday evaluation)', 'Grund (z. B. Montagsbewertung)', 'Motif (p. ex. évaluation du lundi)'],
            'skiSchool.field.weekStart' => ['Inizio settimana', 'Week start', 'Wochenbeginn', 'Début de semaine'],
            'skiSchool.field.withholdingPercent' => ['Ritenuta (%)', 'Withholding (%)', 'Quellenabzug (%)', 'Retenue à la source (%)'],
            'skiSchool.filter.availableAt' => ['Libero alle', 'Free at', 'Frei um', 'Libre à'],
            'skiSchool.filter.date' => ['Giorno', 'Day', 'Tag', 'Jour'],
            'skiSchool.filter.discipline' => ['Disciplina', 'Discipline', 'Disziplin', 'Discipline'],
            'skiSchool.filter.language' => ['Lingua', 'Language', 'Sprache', 'Langue'],
            'skiSchool.filter.month' => ['Mese', 'Month', 'Monat', 'Mois'],
            'skiSchool.filter.requestedInstructor' => ['Maestro richiesto', 'Requested instructor', 'Gewünschter Lehrer', 'Moniteur demandé'],
            'skiSchool.filter.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'skiSchool.filter.week' => ['Settimana (lunedì)', 'Week (Monday)', 'Woche (Montag)', 'Semaine (lundi)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function columns(): array
    {
        return [
            'skiSchool.column.code' => ['Codice', 'Code', 'Code', 'Code'],
            'skiSchool.column.date' => ['Data', 'Date', 'Datum', 'Date'],
            'skiSchool.column.discipline' => ['Disciplina e lingua', 'Discipline and language', 'Disziplin und Sprache', 'Discipline et langue'],
            'skiSchool.column.disciplines' => ['Discipline', 'Disciplines', 'Disziplinen', 'Disciplines'],
            'skiSchool.column.gross' => ['Lordo', 'Gross', 'Brutto', 'Brut'],
            'skiSchool.column.hourlyRate' => ['Tariffa oraria', 'Hourly rate', 'Stundensatz', 'Tarif horaire'],
            'skiSchool.column.hours' => ['Ore', 'Hours', 'Stunden', 'Heures'],
            'skiSchool.column.instructor' => ['Maestro', 'Instructor', 'Lehrer', 'Moniteur'],
            'skiSchool.column.languages' => ['Lingue', 'Languages', 'Sprachen', 'Langues'],
            'skiSchool.column.level' => ['Livello', 'Level', 'Stufe', 'Niveau'],
            'skiSchool.column.license' => ['Qualifica', 'Qualification', 'Qualifikation', 'Qualification'],
            'skiSchool.column.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'skiSchool.column.net' => ['Netto', 'Net', 'Netto', 'Net'],
            'skiSchool.column.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'skiSchool.column.places' => ['Posti', 'Places', 'Plätze', 'Places'],
            'skiSchool.column.regime' => ['Regime', 'Regime', 'Regime', 'Régime'],
            'skiSchool.column.retention' => ['Trattenuta segreteria', 'Secretariat retention', 'Sekretariatsabzug', 'Retenue de secrétariat'],
            'skiSchool.column.source' => ['Origine', 'Source', 'Herkunft', 'Origine'],
            'skiSchool.column.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'skiSchool.column.withholding' => ['Ritenuta', 'Withholding', 'Quellenabzug', 'Retenue à la source'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'skiSchool.action.add' => ['Aggiungi', 'Add', 'Hinzufügen', 'Ajouter'],
            'skiSchool.action.addItem' => ['Aggiungi articolo', 'Add item', 'Artikel hinzufügen', 'Ajouter un article'],
            'skiSchool.action.assign' => ['Assegna', 'Assign', 'Zuweisen', 'Attribuer'],
            'skiSchool.action.assignRotation' => ['Assegna con rotazione equa', 'Assign by fair rotation', 'Mit fairer Rotation zuweisen', 'Attribuer par rotation équitable'],
            'skiSchool.action.availability' => ['Disponibilità', 'Availability', 'Verfügbarkeit', 'Disponibilité'],
            'skiSchool.action.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'skiSchool.action.book' => ['Prenota', 'Book', 'Buchen', 'Réserver'],
            'skiSchool.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'skiSchool.action.cancelLesson' => ['Annulla lezione', 'Cancel lesson', 'Lektion stornieren', 'Annuler la leçon'],
            'skiSchool.action.clearSelection' => ['Azzera selezione', 'Clear selection', 'Auswahl löschen', 'Effacer la sélection'],
            'skiSchool.action.commissionRules' => ['Regole di compenso', 'Commission rules', 'Honorarregeln', 'Règles de rémunération'],
            'skiSchool.action.complete' => ['Segna come svolta', 'Mark as taught', 'Als durchgeführt markieren', 'Marquer comme effectuée'],
            'skiSchool.action.confirmBooking' => ['Conferma la prenotazione', 'Confirm booking', 'Buchung bestätigen', 'Confirmer la réservation'],
            'skiSchool.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'skiSchool.action.discard' => ['Scarta bozza', 'Discard draft', 'Entwurf verwerfen', 'Rejeter le brouillon'],
            'skiSchool.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'skiSchool.action.enroll' => ['Iscrivi', 'Enrol', 'Anmelden', 'Inscrire'],
            'skiSchool.action.generate' => ['Genera', 'Generate', 'Erstellen', 'Générer'],
            'skiSchool.action.generateStatements' => ['Genera estratti conto del mese', 'Generate the month statements', 'Monatsabrechnungen erstellen', 'Générer les relevés du mois'],
            'skiSchool.action.handOver' => ['Consegna', 'Hand over', 'Übergeben', 'Remettre'],
            'skiSchool.action.invoice' => ['Fattura', 'Invoice', 'Fakturieren', 'Facturer'],
            'skiSchool.action.issue' => ['Emetti', 'Issue', 'Ausstellen', 'Émettre'],
            'skiSchool.action.markPaid' => ['Segna come pagato', 'Mark as paid', 'Als bezahlt markieren', 'Marquer comme payé'],
            'skiSchool.action.newCourse' => ['Nuovo corso', 'New course', 'Neuer Kurs', 'Nouveau cours'],
            'skiSchool.action.newInstructor' => ['Nuovo maestro', 'New instructor', 'Neuer Lehrer', 'Nouveau moniteur'],
            'skiSchool.action.newLesson' => ['Nuova lezione', 'New lesson', 'Neue Lektion', 'Nouvelle leçon'],
            'skiSchool.action.next' => ['Avanti', 'Next', 'Weiter', 'Suivant'],
            'skiSchool.action.noShow' => ['Cliente assente', 'Customer no-show', 'Kunde nicht erschienen', 'Client absent'],
            'skiSchool.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'skiSchool.action.package' => ['Pacchetto', 'Package', 'Paket', 'Forfait'],
            'skiSchool.action.pdf' => ['Stampa PDF', 'Print PDF', 'PDF drucken', 'Imprimer le PDF'],
            'skiSchool.action.printBibs' => ['Stampa pettorine', 'Print bibs', 'Startnummern drucken', 'Imprimer les dossards'],
            'skiSchool.action.reschedule' => ['Sposta lezione', 'Reschedule', 'Lektion verschieben', 'Déplacer la leçon'],
            'skiSchool.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'skiSchool.action.saveAttendance' => ['Salva presenze', 'Save attendance', 'Anwesenheit speichern', 'Enregistrer les présences'],
            'skiSchool.action.savePackage' => ['Salva pacchetto', 'Save package', 'Paket speichern', 'Enregistrer le forfait'],
            'skiSchool.action.showRanking' => ['Mostra classifica rotazione', 'Show rotation ranking', 'Rotationsrangliste anzeigen', 'Afficher le classement de rotation'],
            'skiSchool.action.takeBack' => ['Ritira', 'Take back', 'Zurücknehmen', 'Reprendre'],
            'skiSchool.action.transfer' => ['Sposta {count} allievi', 'Move {count} students', '{count} Schüler verschieben', 'Déplacer {count} élèves'],
            'skiSchool.action.withdraw' => ['Ritira dal corso', 'Withdraw', 'Abmelden', 'Désinscrire'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'skiSchool.section.attendance' => ['Presenze', 'Attendance', 'Anwesenheit', 'Présences'],
            'skiSchool.section.availability' => ['Disponibilità e indisponibilità', 'Availability and blackouts', 'Verfügbarkeit und Sperren', 'Disponibilités et indisponibilités'],
            'skiSchool.section.bookLesson' => ['Prenota una lezione privata', 'Book a private lesson', 'Privatlektion buchen', 'Réserver une leçon privée'],
            'skiSchool.section.course' => ['Corso', 'Course', 'Kurs', 'Cours'],
            'skiSchool.section.courseForm' => ['Corso collettivo settimanale', 'Weekly group course', 'Wöchentlicher Gruppenkurs', 'Cours collectif hebdomadaire'],
            'skiSchool.section.coursesSubtitle' => ['Gruppi per livello, spostamento degli allievi dopo la valutazione del lunedì, presenze e pettorine.', 'Groups by level, moving students after the Monday evaluation, attendance and bibs.', 'Gruppen nach Stufe, Verschieben der Schüler nach der Montagsbewertung, Anwesenheit und Startnummern.', 'Groupes par niveau, déplacement des élèves après l\'évaluation du lundi, présences et dossards.'],
            'skiSchool.section.dashboardSubtitle' => ['Lezioni, corsi, maestri, fatturazione e compensi in un colpo d\'occhio.', 'Lessons, courses, instructors, billing and payouts at a glance.', 'Lektionen, Kurse, Lehrer, Fakturierung und Honorare auf einen Blick.', 'Leçons, cours, moniteurs, facturation et rémunérations en un coup d\'œil.'],
            'skiSchool.section.enroll' => ['Iscrivi un allievo', 'Enrol a student', 'Schüler anmelden', 'Inscrire un élève'],
            'skiSchool.section.instructorForm' => ['Maestro o guida', 'Instructor or guide', 'Lehrer oder Führer', 'Moniteur ou guide'],
            'skiSchool.section.instructorsSubtitle' => ['Qualifiche, lingue, discipline, tariffe e regole di trattenuta che alimentano i compensi.', 'Qualifications, languages, disciplines, rates and the retention rules that feed the payouts.', 'Qualifikationen, Sprachen, Disziplinen, Tarife und Abzugsregeln, die die Honorare speisen.', 'Qualifications, langues, disciplines, tarifs et règles de retenue qui alimentent les rémunérations.'],
            'skiSchool.section.lesson' => ['Lezione privata', 'Private lesson', 'Privatlektion', 'Leçon privée'],
            'skiSchool.section.package' => ['Pacchetto: noleggio, kit ARTVA e skipass', 'Package: rental, ARTVA kit and skipass', 'Paket: Miete, ARTVA-Set und Skipass', 'Forfait : location, kit ARTVA et forfait de ski'],
            'skiSchool.section.payoutsSubtitle' => ['Ore, compensi lordi, trattenute di segreteria, ritenute e netto da pagare per ogni maestro.', 'Hours, gross fees, secretariat retentions, withholdings and net payable per instructor.', 'Stunden, Bruttohonorare, Sekretariatsabzüge, Quellenabzüge und Nettoauszahlung je Lehrer.', 'Heures, rémunérations brutes, retenues de secrétariat, retenues à la source et net à payer par moniteur.'],
            'skiSchool.section.planningSubtitle' => ['Griglia ad alta densità con slot da 30 o 55 minuti, filtri istantanei e rotazione equa dei maestri.', 'High-density grid with 30 or 55 minute slots, instant filters and fair instructor rotation.', 'Hochdichtes Raster mit 30- oder 55-Minuten-Slots, Sofortfiltern und fairer Lehrerrotation.', 'Grille haute densité avec créneaux de 30 ou 55 minutes, filtres instantanés et rotation équitable des moniteurs.'],
            'skiSchool.section.quickAssign' => ['Assegnazione rapida (classifica di rotazione)', 'Quick assign (rotation ranking)', 'Schnellzuweisung (Rotationsrangliste)', 'Attribution rapide (classement de rotation)'],
            'skiSchool.section.settings' => ['Impostazioni della scuola', 'School settings', 'Einstellungen der Schule', 'Paramètres de l\'école'],
            'skiSchool.section.statement' => ['Estratto conto compensi', 'Payout statement', 'Honorarabrechnung', 'Relevé de rémunération'],
            'skiSchool.section.unassigned' => ['Lezioni da assegnare', 'Lessons to assign', 'Zuzuweisende Lektionen', 'Leçons à attribuer'],
            'skiSchool.tab.courses' => ['Elenco corsi', 'Course list', 'Kursliste', 'Liste des cours'],
            'skiSchool.tab.levelBoard' => ['Livelli e gruppi', 'Levels and groups', 'Stufen und Gruppen', 'Niveaux et groupes'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'skiSchool.message.attendanceSaved' => ['Presenze salvate.', 'Attendance saved.', 'Anwesenheit gespeichert.', 'Présences enregistrées.'],
            'skiSchool.message.availabilityRemoved' => ['Disponibilità rimossa.', 'Availability removed.', 'Verfügbarkeit entfernt.', 'Disponibilité supprimée.'],
            'skiSchool.message.availabilitySaved' => ['Disponibilità salvata.', 'Availability saved.', 'Verfügbarkeit gespeichert.', 'Disponibilité enregistrée.'],
            'skiSchool.message.courseSaved' => ['Corso salvato.', 'Course saved.', 'Kurs gespeichert.', 'Cours enregistré.'],
            'skiSchool.message.courseStatusChanged' => ['Stato del corso aggiornato.', 'Course status updated.', 'Kursstatus aktualisiert.', 'Statut du cours mis à jour.'],
            'skiSchool.message.instructorDeleted' => ['Maestro eliminato.', 'Instructor deleted.', 'Lehrer gelöscht.', 'Moniteur supprimé.'],
            'skiSchool.message.instructorSaved' => ['Maestro salvato.', 'Instructor saved.', 'Lehrer gespeichert.', 'Moniteur enregistré.'],
            'skiSchool.message.invoiced' => ['Fattura creata.', 'Invoice created.', 'Rechnung erstellt.', 'Facture créée.'],
            'skiSchool.message.lessonAssigned' => ['Maestro assegnato.', 'Instructor assigned.', 'Lehrer zugewiesen.', 'Moniteur attribué.'],
            'skiSchool.message.lessonBooked' => ['Lezione prenotata.', 'Lesson booked.', 'Lektion gebucht.', 'Leçon réservée.'],
            'skiSchool.message.lessonCancelled' => ['Lezione annullata.', 'Lesson cancelled.', 'Lektion storniert.', 'Leçon annulée.'],
            'skiSchool.message.lessonCompleted' => ['Lezione svolta: compenso del maestro registrato.', 'Lesson taught: the instructor fee was recorded.', 'Lektion durchgeführt: Honorar des Lehrers erfasst.', 'Leçon effectuée : la rémunération du moniteur est enregistrée.'],
            'skiSchool.message.lessonRescheduled' => ['Lezione spostata.', 'Lesson rescheduled.', 'Lektion verschoben.', 'Leçon déplacée.'],
            'skiSchool.message.packageItemSaved' => ['Articolo aggiornato.', 'Item updated.', 'Artikel aktualisiert.', 'Article mis à jour.'],
            'skiSchool.message.packageSaved' => ['Pacchetto salvato.', 'Package saved.', 'Paket gespeichert.', 'Forfait enregistré.'],
            'skiSchool.message.settingsSaved' => ['Impostazioni salvate.', 'Settings saved.', 'Einstellungen gespeichert.', 'Paramètres enregistrés.'],
            'skiSchool.message.statementDiscarded' => ['Bozza scartata.', 'Draft discarded.', 'Entwurf verworfen.', 'Brouillon rejeté.'],
            'skiSchool.message.statementIssued' => ['Estratto conto emesso.', 'Statement issued.', 'Abrechnung ausgestellt.', 'Relevé émis.'],
            'skiSchool.message.statementPaid' => ['Estratto conto pagato.', 'Statement paid.', 'Abrechnung bezahlt.', 'Relevé payé.'],
            'skiSchool.message.statementsGenerated' => ['Estratti conto generati.', 'Statements generated.', 'Abrechnungen erstellt.', 'Relevés générés.'],
            'skiSchool.message.studentEnrolled' => ['Allievo iscritto.', 'Student enrolled.', 'Schüler angemeldet.', 'Élève inscrit.'],
            'skiSchool.message.studentsTransferred' => ['Allievi spostati: presenze e pacchetto restano con l\'allievo.', 'Students moved: attendance and package stay with the student.', 'Schüler verschoben: Anwesenheit und Paket bleiben beim Schüler.', 'Élèves déplacés : présences et forfait restent avec l\'élève.'],
            'skiSchool.message.studentWithdrawn' => ['Allievo ritirato.', 'Student withdrawn.', 'Schüler abgemeldet.', 'Élève désinscrit.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function labels(): array
    {
        return [
            'skiSchool.alert.capacityExceeded' => ['Questi gruppi supererebbero la capienza: {groups}.', 'These groups would exceed their capacity: {groups}.', 'Diese Gruppen würden ihre Kapazität überschreiten: {groups}.', 'Ces groupes dépasseraient leur capacité : {groups}.'],
            'skiSchool.alert.licenses' => ['{count} qualifiche in scadenza entro 60 giorni.', '{count} licences expire within 60 days.', '{count} Ausweise laufen innerhalb von 60 Tagen ab.', '{count} licences expirent dans les 60 jours.'],
            'skiSchool.alert.skippedStatements' => ['{count} maestri hanno già un estratto emesso: non è stato rigenerato.', '{count} instructors already have an issued statement: it was not regenerated.', '{count} Lehrer haben bereits eine ausgestellte Abrechnung: Sie wurde nicht neu erstellt.', '{count} moniteurs ont déjà un relevé émis : il n\'a pas été régénéré.'],
            'skiSchool.alert.unassigned' => ['{count} lezioni senza maestro assegnato.', '{count} lessons without an assigned instructor.', '{count} Lektionen ohne zugewiesenen Lehrer.', '{count} leçons sans moniteur attribué.'],
            'skiSchool.kpi.courses' => ['Corsi in corso', 'Running courses', 'Laufende Kurse', 'Cours en cours'],
            'skiSchool.kpi.gross' => ['Compensi lordi', 'Gross fees', 'Bruttohonorare', 'Rémunérations brutes'],
            'skiSchool.kpi.instructors' => ['Maestri attivi', 'Active instructors', 'Aktive Lehrer', 'Moniteurs actifs'],
            'skiSchool.kpi.lessonsToday' => ['Lezioni oggi', 'Lessons today', 'Lektionen heute', 'Leçons aujourd\'hui'],
            'skiSchool.kpi.lessonsWeek' => ['Lezioni della settimana', 'Lessons this week', 'Lektionen dieser Woche', 'Leçons de la semaine'],
            'skiSchool.kpi.monthPayable' => ['Netto da pagare {month}', 'Net payable {month}', 'Nettoauszahlung {month}', 'Net à payer {month}'],
            'skiSchool.kpi.netPayable' => ['Netto da pagare', 'Net payable', 'Nettoauszahlung', 'Net à payer'],
            'skiSchool.kpi.retention' => ['Trattenute di segreteria', 'Secretariat retentions', 'Sekretariatsabzüge', 'Retenues de secrétariat'],
            'skiSchool.kpi.students' => ['Allievi iscritti', 'Enrolled students', 'Angemeldete Schüler', 'Élèves inscrits'],
            'skiSchool.kpi.toInvoice' => ['Da fatturare', 'To invoice', 'Zu fakturieren', 'À facturer'],
            'skiSchool.kpi.unassigned' => ['Da assegnare', 'To assign', 'Zuzuweisen', 'À attribuer'],
            'skiSchool.label.addonsInvoiceSplit' => ['i servizi imponibili sono su una seconda fattura (un documento italiano ha una sola natura IVA)', 'the taxable add-ons are on a second invoice (an Italian document carries a single VAT nature)', 'die steuerpflichtigen Zusatzleistungen stehen auf einer zweiten Rechnung (ein italienisches Dokument hat nur eine MWST-Natur)', 'les services imposables figurent sur une seconde facture (un document italien n\'a qu\'une nature de TVA)'],
            'skiSchool.label.addOnsNet' => ['Totale netto servizi aggiuntivi', 'Add-ons net total', 'Nettosumme der Zusatzleistungen', 'Total net des services additionnels'],
            'skiSchool.label.alreadyInvoiced' => ['Già fatturato: il pacchetto non è più modificabile.', 'Already invoiced: the package can no longer be changed.', 'Bereits fakturiert: Das Paket kann nicht mehr geändert werden.', 'Déjà facturé : le forfait ne peut plus être modifié.'],
            'skiSchool.label.attendanceRate' => ['Presenze', 'Attendance', 'Anwesenheit', 'Présences'],
            'skiSchool.label.customerToInvoice' => ['Cliente a cui intestare la fattura', 'Customer to invoice', 'Rechnungsempfänger', 'Client à facturer'],
            'skiSchool.label.exemption' => ['Trattamento IVA della lezione', 'VAT treatment of the instruction', 'MWST-Behandlung des Unterrichts', 'Traitement TVA de l\'enseignement'],
            'skiSchool.label.invoiceTotals' => ['Netto {net}, IVA {vat}, totale {gross}', 'Net {net}, VAT {vat}, total {gross}', 'Netto {net}, MWST {vat}, Total {gross}', 'Net {net}, TVA {vat}, total {gross}'],
            'skiSchool.label.lessonPrice' => ['Prezzo netto della lezione', 'Net lesson price', 'Nettopreis der Lektion', 'Prix net de la leçon'],
            'skiSchool.label.moveTo' => ['Sposta in…', 'Move to…', 'Verschieben nach…', 'Déplacer vers…'],
            'skiSchool.label.noAvailability' => ['Nessuna disponibilità o indisponibilità inserita.', 'No availability or blackout entered.', 'Keine Verfügbarkeit oder Sperre eingetragen.', 'Aucune disponibilité ou indisponibilité saisie.'],
            'skiSchool.label.noGroups' => ['Nessun gruppo in questa settimana.', 'No group this week.', 'Keine Gruppe in dieser Woche.', 'Aucun groupe cette semaine.'],
            'skiSchool.label.noInstructors' => ['Nessun maestro corrisponde ai filtri.', 'No instructor matches the filters.', 'Kein Lehrer entspricht den Filtern.', 'Aucun moniteur ne correspond aux filtres.'],
            'skiSchool.label.noPackage' => ['Nessun articolo nel pacchetto.', 'No item in the package.', 'Kein Artikel im Paket.', 'Aucun article dans le forfait.'],
            'skiSchool.label.noPayouts' => ['Nessun compenso in questo mese.', 'No fees this month.', 'Keine Honorare in diesem Monat.', 'Aucune rémunération ce mois-ci.'],
            'skiSchool.label.noStudents' => ['Nessun allievo.', 'No students.', 'Keine Schüler.', 'Aucun élève.'],
            'skiSchool.label.publicLink' => ['Pagina pubblica di prenotazione', 'Public booking page', 'Öffentliche Buchungsseite', 'Page publique de réservation'],
            'skiSchool.label.requested' => ['Richiesto', 'Requested', 'Gewünscht', 'Demandé'],
            'skiSchool.label.rotationHint' => ['Classifica per ore già assegnate nella settimana (meno ore = prima); il maestro richiesto ha la precedenza se disponibile.', 'Ranked by hours already assigned this week (fewest first); the requested instructor comes first when available.', 'Rangfolge nach bereits zugewiesenen Stunden dieser Woche (wenigste zuerst); der gewünschte Lehrer steht zuerst, wenn verfügbar.', 'Classement par heures déjà attribuées cette semaine (le moins chargé d\'abord) ; le moniteur demandé passe en premier s\'il est disponible.'],
            'skiSchool.label.suggested' => ['Consigliato', 'Suggested', 'Vorgeschlagen', 'Suggéré'],
            'skiSchool.label.unassigned' => ['Da assegnare', 'Unassigned', 'Nicht zugewiesen', 'Non attribué'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function publicPortal(): array
    {
        return [
            'skiSchool.public.addons' => ['Noleggio, kit valanghe e skipass (facoltativi)', 'Rental, avalanche kit and skipass (optional)', 'Miete, Lawinen-Set und Skipass (optional)', 'Location, kit avalanche et forfait (facultatifs)'],
            'skiSchool.public.anyInstructor' => ['Nessuna preferenza: scegliete voi', 'No preference: you choose', 'Keine Präferenz: Sie wählen', 'Pas de préférence : à vous de choisir'],
            'skiSchool.public.bookAnother' => ['Prenota un\'altra attività', 'Book another activity', 'Weitere Aktivität buchen', 'Réserver une autre activité'],
            'skiSchool.public.confirmed' => ['Prenotazione ricevuta, grazie!', 'Booking received, thank you!', 'Buchung erhalten, vielen Dank!', 'Réservation reçue, merci !'],
            'skiSchool.public.courseInstructorHint' => ['Il maestro del gruppo è assegnato dalla scuola.', 'The school assigns the instructor of the group.', 'Die Schule weist den Lehrer der Gruppe zu.', 'L\'école attribue le moniteur du groupe.'],
            'skiSchool.public.days' => ['giorni', 'days', 'Tage', 'jours'],
            'skiSchool.public.freePlaces' => ['{count} posti liberi', '{count} free places', '{count} freie Plätze', '{count} places libres'],
            'skiSchool.public.instructorHint' => ['Potete chiedere un maestro in particolare: se è libero avrà la precedenza.', 'You may ask for a particular instructor: if free, they take priority.', 'Sie können einen bestimmten Lehrer wünschen: Wenn frei, hat er Vorrang.', 'Vous pouvez demander un moniteur en particulier : s\'il est libre, il a la priorité.'],
            'skiSchool.public.language' => ['Lingua della pagina', 'Page language', 'Sprache der Seite', 'Langue de la page'],
            'skiSchool.public.mode.course' => ['Corso settimanale di gruppo', 'Weekly group course', 'Wöchentlicher Gruppenkurs', 'Cours collectif hebdomadaire'],
            'skiSchool.public.mode.lesson' => ['Lezione privata', 'Private lesson', 'Privatlektion', 'Leçon privée'],
            'skiSchool.public.noCourses' => ['Nessun corso con posti liberi per questa scelta.', 'No course with free places for this choice.', 'Kein Kurs mit freien Plätzen für diese Auswahl.', 'Aucun cours avec des places libres pour ce choix.'],
            'skiSchool.public.noSlots' => ['Nessun orario libero in questo giorno.', 'No free time on this day.', 'Keine freie Zeit an diesem Tag.', 'Aucune heure libre ce jour-là.'],
            'skiSchool.public.notFound' => ['Questa pagina di prenotazione non esiste o non è attiva.', 'This booking page does not exist or is not active.', 'Diese Buchungsseite existiert nicht oder ist nicht aktiv.', 'Cette page de réservation n\'existe pas ou n\'est pas active.'],
            'skiSchool.public.pendingAssignment' => ['La scuola vi confermerà il maestro appena possibile.', 'The school will confirm your instructor as soon as possible.', 'Die Schule bestätigt Ihren Lehrer so bald wie möglich.', 'L\'école vous confirmera votre moniteur dès que possible.'],
            'skiSchool.public.reference' => ['Codice prenotazione: {reference}', 'Booking reference: {reference}', 'Buchungsnummer: {reference}', 'Référence de réservation : {reference}'],
            'skiSchool.public.showCourses' => ['Mostra i corsi', 'Show courses', 'Kurse anzeigen', 'Afficher les cours'],
            'skiSchool.public.showSlots' => ['Mostra gli orari liberi', 'Show free times', 'Freie Zeiten anzeigen', 'Afficher les heures libres'],
            'skiSchool.public.step.addons' => ['Noleggio e skipass', 'Rental and skipass', 'Miete und Skipass', 'Location et forfait'],
            'skiSchool.public.step.confirmation' => ['Conferma', 'Confirmation', 'Bestätigung', 'Confirmation'],
            'skiSchool.public.step.course' => ['Livello del corso', 'Course level', 'Kursstufe', 'Niveau du cours'],
            'skiSchool.public.step.dateTime' => ['Data e ora', 'Date and time', 'Datum und Zeit', 'Date et heure'],
            'skiSchool.public.step.discipline' => ['Disciplina e lingua', 'Discipline and language', 'Disziplin und Sprache', 'Discipline et langue'],
            'skiSchool.public.step.instructor' => ['Maestro', 'Instructor', 'Lehrer', 'Moniteur'],
            'skiSchool.public.vatNote' => ['L\'insegnamento è esente da IVA ({reference}); noleggio e skipass sono imponibili.', 'Instruction is exempt from VAT ({reference}); rental and skipass are taxable.', 'Der Unterricht ist von der MWST befreit ({reference}); Miete und Skipass sind steuerpflichtig.', 'L\'enseignement est exonéré de TVA ({reference}) ; la location et le forfait sont imposables.'],
            'skiSchool.public.yourDetails' => ['I vostri dati', 'Your details', 'Ihre Angaben', 'Vos coordonnées'],
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
