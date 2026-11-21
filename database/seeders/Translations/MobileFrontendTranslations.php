<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the Flutter mobile companion (it, en, de, fr). Merged by FrontendTranslationSeeder::dictionary().
 */
final class MobileFrontendTranslations
{
    private const KEYS = [
        'mobile.login.title', 'mobile.login.email', 'mobile.login.password', 'mobile.login.submit',
        'mobile.login.twoFactor', 'mobile.login.failed', 'mobile.home.title', 'mobile.home.noModules',
        'mobile.home.signOut', 'mobile.sync.online', 'mobile.sync.offline', 'mobile.sync.syncing',
        'mobile.sync.error', 'mobile.sync.retry', 'mobile.tenant.switch', 'mobile.tenant.active',
        'mobile.module.clock', 'mobile.module.shifts', 'mobile.module.leave', 'mobile.module.expenses',
        'mobile.module.payslips', 'mobile.module.approvals_inbox', 'mobile.module.barcode_scanner',
        'mobile.module.inventory_counts', 'mobile.module.pick_lists', 'mobile.module.work_orders',
        'mobile.module.service_reports', 'mobile.module.appointments', 'mobile.module.customer_360',
        'mobile.module.quotes', 'mobile.module.kpi_dashboard', 'mobile.module.copilot', 'mobile.module.executive_dss',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $texts = match ($locale) {
            'it' => [
                'Accedi', 'Email', 'Password', 'Accedi', 'Autenticazione a due fattori richiesta: completa l\'accesso dal browser.',
                'Accesso non riuscito', 'Home', 'Nessun modulo disponibile', 'Esci', 'Sincronizzato', 'Modalità offline',
                'Sincronizzazione di {count} elementi', 'Errore di sincronizzazione', 'Riprova', 'Cambia azienda', 'Azienda attiva',
                'Timbratura', 'Turni', 'Permessi', 'Note spese', 'Cedolini', 'Approvazioni', 'Scanner codici',
                'Inventari', 'Prelievi', 'Ordini di lavoro', 'Rapporti di intervento', 'Appuntamenti', 'Cliente 360',
                'Preventivi', 'Cruscotto KPI', 'Copilot', 'Cruscotto direzionale',
            ],
            'en' => [
                'Sign in', 'Email', 'Password', 'Sign in', 'Two-factor authentication is required: complete the sign-in in your browser.',
                'Sign-in failed', 'Home', 'No modules available', 'Sign out', 'Synced', 'Offline mode',
                'Syncing {count} items', 'Sync error', 'Retry', 'Switch company', 'Active company',
                'Clock in/out', 'Shifts', 'Leave', 'Expenses', 'Payslips', 'Approvals', 'Barcode scanner',
                'Inventory counts', 'Pick lists', 'Work orders', 'Service reports', 'Appointments', 'Customer 360',
                'Quotes', 'KPI dashboard', 'Copilot', 'Executive DSS',
            ],
            'de' => [
                'Anmelden', 'E-Mail', 'Passwort', 'Anmelden', 'Zwei-Faktor-Authentifizierung erforderlich: Schliessen Sie die Anmeldung im Browser ab.',
                'Anmeldung fehlgeschlagen', 'Startseite', 'Keine Module verfügbar', 'Abmelden', 'Synchronisiert', 'Offline-Modus',
                '{count} Elemente werden synchronisiert', 'Synchronisierungsfehler', 'Erneut versuchen', 'Firma wechseln', 'Aktive Firma',
                'Zeiterfassung', 'Schichten', 'Abwesenheiten', 'Spesen', 'Lohnabrechnungen', 'Genehmigungen', 'Barcode-Scanner',
                'Inventuren', 'Kommissionierlisten', 'Arbeitsaufträge', 'Serviceberichte', 'Termine', 'Kunde 360',
                'Angebote', 'KPI-Dashboard', 'Copilot', 'Management-Cockpit',
            ],
            'fr' => [
                'Se connecter', 'E-mail', 'Mot de passe', 'Se connecter', 'Authentification à deux facteurs requise : terminez la connexion dans votre navigateur.',
                'Échec de la connexion', 'Accueil', 'Aucun module disponible', 'Se déconnecter', 'Synchronisé', 'Mode hors ligne',
                'Synchronisation de {count} éléments', 'Erreur de synchronisation', 'Réessayer', 'Changer d\'entreprise', 'Entreprise active',
                'Pointage', 'Équipes', 'Congés', 'Notes de frais', 'Fiches de paie', 'Approbations', 'Scanner de codes',
                'Inventaires', 'Listes de prélèvement', 'Ordres de travail', 'Rapports d\'intervention', 'Rendez-vous', 'Client 360',
                'Devis', 'Tableau de bord KPI', 'Copilot', 'Cockpit de direction',
            ],
            default => [],
        };

        return $texts === [] ? [] : array_combine(self::KEYS, $texts);
    }
}
