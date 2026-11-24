<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * Frontend strings of the Academy web UI (Mod 112): hub, tracks and missions, spotlight tour, contextual help drawer and
 * classroom mode (it, en, de, fr). Merged by FrontendTranslationSeeder::dictionary().
 */
final class AcademyFrontendTranslations
{
    /**
     * Each entry is a tuple in the order Italian, English, German, French.
     *
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

        $translations = [];

        foreach (self::entries() as $key => $tuple) {
            $translations[$key] = $tuple[$index];
        }

        return $translations;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function entries(): array
    {
        return [
            'ACADEMY.NAV' => ['Academy', 'Academy', 'Academy', 'Academy'],
            'ACADEMY.TITLE' => ['Academy FatturaFacile', 'FatturaFacile Academy', 'FatturaFacile Academy', 'Académie FatturaFacile'],
            'ACADEMY.SUBTITLE' => ['Percorsi formativi, missioni pratiche in sandbox e certificati verificabili.', 'Learning tracks, hands-on sandbox missions and verifiable certificates.', 'Lernpfade, praktische Sandbox-Missionen und überprüfbare Zertifikate.', 'Parcours de formation, missions pratiques en sandbox et certificats vérifiables.'],
            'ACADEMY.BACK_TO_HUB' => ['Torna all\'Academy', 'Back to the Academy', 'Zurück zur Academy', 'Retour à l\'Académie'],
            'ACADEMY.TRACKS' => ['Percorsi', 'Learning tracks', 'Lernpfade', 'Parcours'],
            'ACADEMY.NO_TRACKS' => ['Nessun percorso disponibile.', 'No learning tracks available.', 'Keine Lernpfade verfügbar.', 'Aucun parcours disponible.'],
            'ACADEMY.PROGRESS' => ['Avanzamento', 'Progress', 'Fortschritt', 'Progression'],
            'ACADEMY.XP' => ['Punti XP', 'XP points', 'XP-Punkte', 'Points XP'],
            'ACADEMY.START' => ['Avvia', 'Start', 'Starten', 'Démarrer'],
            'ACADEMY.RESUME' => ['Riprendi', 'Resume', 'Fortsetzen', 'Reprendre'],
            'ACADEMY.VERIFY' => ['Verifica', 'Verify', 'Prüfen', 'Vérifier'],
            'ACADEMY.STATUS_NOT_STARTED' => ['Da iniziare', 'Not started', 'Nicht gestartet', 'Non commencée'],
            'ACADEMY.STATUS_STARTED' => ['In corso', 'In progress', 'In Bearbeitung', 'En cours'],
            'ACADEMY.STATUS_COMPLETED' => ['Completata', 'Completed', 'Abgeschlossen', 'Terminée'],
            'ACADEMY.MISSION_COMPLETED' => ['Missione completata.', 'Mission completed.', 'Mission abgeschlossen.', 'Mission terminée.'],
            'ACADEMY.START_ERROR' => ['Impossibile avviare la missione.', 'The mission could not be started.', 'Die Mission konnte nicht gestartet werden.', 'La mission n\'a pas pu être démarrée.'],
            'ACADEMY.LOAD_ERROR' => ['Impossibile caricare i dati dell\'Academy.', 'The Academy data could not be loaded.', 'Die Academy-Daten konnten nicht geladen werden.', 'Les données de l\'Académie n\'ont pas pu être chargées.'],
            'ACADEMY.FORBIDDEN' => ['Non hai i permessi per questa azione.', 'You do not have permission for this action.', 'Sie haben keine Berechtigung für diese Aktion.', 'Vous n\'avez pas l\'autorisation d\'effectuer cette action.'],
            'ACADEMY.SANDBOX' => ['Tenant sandbox', 'Sandbox tenant', 'Sandbox-Mandant', 'Tenant sandbox'],
            'ACADEMY.SANDBOX_REQUIRED' => ['Le missioni si svolgono solo in un tenant demo o sandbox.', 'Missions can only be run in a demo or sandbox tenant.', 'Missionen können nur in einem Demo- oder Sandbox-Mandanten ausgeführt werden.', 'Les missions ne peuvent être réalisées que dans un tenant démo ou sandbox.'],
            'ACADEMY.CERTIFICATES' => ['Certificati', 'Certificates', 'Zertifikate', 'Certificats'],
            'ACADEMY.DOWNLOAD_CERTIFICATE' => ['Scarica il certificato', 'Download the certificate', 'Zertifikat herunterladen', 'Télécharger le certificat'],
            'ACADEMY.CERTIFICATE_ERROR' => ['Il certificato non è disponibile.', 'The certificate is not available.', 'Das Zertifikat ist nicht verfügbar.', 'Le certificat n\'est pas disponible.'],
            'ACADEMY.HELP_TITLE' => ['Guida alla schermata', 'Help for this screen', 'Hilfe zu diesem Bildschirm', 'Aide pour cet écran'],
            'ACADEMY.HELP_EMPTY' => ['Per questa schermata non è ancora disponibile una guida.', 'No help is available for this screen yet.', 'Für diesen Bildschirm ist noch keine Hilfe verfügbar.', 'Aucune aide n\'est encore disponible pour cet écran.'],
            'ACADEMY.OPEN_HELP' => ['Apri la guida contestuale', 'Open contextual help', 'Kontexthilfe öffnen', 'Ouvrir l\'aide contextuelle'],
            'ACADEMY.HELP_TRIGGER' => ['Guida di questa schermata (?)', 'Help for this screen (?)', 'Hilfe zu diesem Bildschirm (?)', 'Aide pour cet écran (?)'],
            'ACADEMY.TOUR_TITLE' => ['Tour guidato', 'Guided tour', 'Geführte Tour', 'Visite guidée'],
            'ACADEMY.TOUR_NEXT' => ['Avanti', 'Next', 'Weiter', 'Suivant'],
            'ACADEMY.TOUR_PREVIOUS' => ['Indietro', 'Back', 'Zurück', 'Précédent'],
            'ACADEMY.TOUR_SKIP' => ['Salta il tour', 'Skip the tour', 'Tour überspringen', 'Passer la visite'],
            'ACADEMY.TOUR_FINISH' => ['Fine', 'Finish', 'Beenden', 'Terminer'],
            'ACADEMY.TOUR_LOCATING' => ['Ricerca dell\'elemento in corso…', 'Looking for the element…', 'Element wird gesucht…', 'Recherche de l\'élément…'],
            'ACADEMY.TOUR_TARGET_MISSING' => ['Elemento non trovato in questa schermata: controlla di essere nella pagina giusta.', 'Element not found on this screen: check that you are on the right page.', 'Element auf diesem Bildschirm nicht gefunden: Prüfen Sie, ob Sie auf der richtigen Seite sind.', 'Élément introuvable sur cet écran : vérifiez que vous êtes sur la bonne page.'],
            'ACADEMY.CLASSROOM' => ['Modalità aula', 'Classroom mode', 'Klassenzimmer-Modus', 'Mode classe'],
            'ACADEMY.CLASSROOM_SUBTITLE' => ['Assegna esercitazioni agli studenti e segui l\'avanzamento su tenant demo isolati.', 'Assign exercises to students and follow their progress on isolated demo tenants.', 'Weisen Sie Studierenden Übungen zu und verfolgen Sie den Fortschritt in isolierten Demo-Mandanten.', 'Attribuez des exercices aux étudiants et suivez leur progression sur des tenants démo isolés.'],
            'ACADEMY.COHORT_NAME' => ['Nome della classe', 'Class name', 'Name der Klasse', 'Nom de la classe'],
            'ACADEMY.CREATE_COHORT' => ['Crea classe', 'Create class', 'Klasse erstellen', 'Créer la classe'],
            'ACADEMY.COHORT_ERROR' => ['Impossibile creare la classe.', 'The class could not be created.', 'Die Klasse konnte nicht erstellt werden.', 'La classe n\'a pas pu être créée.'],
            'ACADEMY.JOIN_CODE' => ['Codice di iscrizione', 'Join code', 'Beitrittscode', 'Code d\'inscription'],
            'ACADEMY.JOIN_COHORT' => ['Unisciti alla classe', 'Join the class', 'Klasse beitreten', 'Rejoindre la classe'],
            'ACADEMY.JOINED' => ['Iscrizione alla classe completata.', 'You joined the class.', 'Sie sind der Klasse beigetreten.', 'Vous avez rejoint la classe.'],
            'ACADEMY.JOIN_ERROR' => ['Codice di iscrizione non valido.', 'The join code is not valid.', 'Der Beitrittscode ist ungültig.', 'Le code d\'inscription n\'est pas valide.'],
            'ACADEMY.DUE_DATE' => ['Scadenza', 'Due date', 'Fälligkeit', 'Échéance'],
            'ACADEMY.STUDENT' => ['Studente', 'Student', 'Studierende/r', 'Étudiant'],
            'ACADEMY.NO_STUDENTS' => ['Nessuno studente iscritto.', 'No students enrolled.', 'Keine Studierenden eingeschrieben.', 'Aucun étudiant inscrit.'],
        ];
    }
}
