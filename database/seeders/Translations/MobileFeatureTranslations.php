<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * Strings of the mobile feature screens (lists, forms, clock, approvals, conflicts) in it, en, de, fr.
 * Each key maps to [it, en, de, fr]. Merged by FrontendTranslationSeeder::dictionary().
 */
final class MobileFeatureTranslations
{
    private const LOCALES = ['it' => 0, 'en' => 1, 'de' => 2, 'fr' => 3];

    private const TEXTS = [
        'mobile.common.add' => ['Aggiungi', 'Add', 'Hinzufügen', 'Ajouter'],
        'mobile.common.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
        'mobile.common.close' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
        'mobile.common.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
        'mobile.common.empty' => ['Nessun elemento', 'Nothing here yet', 'Noch keine Einträge', 'Aucun élément'],
        'mobile.common.required' => ['Campo obbligatorio', 'Required field', 'Pflichtfeld', 'Champ obligatoire'],
        'mobile.common.pending' => ['In attesa di sincronizzazione', 'Waiting to sync', 'Wartet auf Synchronisierung', 'En attente de synchronisation'],
        'mobile.common.conflict' => ['Conflitto da risolvere', 'Conflict to resolve', 'Konflikt zu lösen', 'Conflit à résoudre'],
        'mobile.common.unavailable' => ['Questa funzione non è ancora disponibile nell\'app.', 'This feature is not available in the app yet.', 'Diese Funktion ist in der App noch nicht verfügbar.', 'Cette fonction n\'est pas encore disponible dans l\'application.'],
        'mobile.entity.contacts' => ['Contatti', 'Contacts', 'Kontakte', 'Contacts'],
        'mobile.entity.products' => ['Prodotti', 'Products', 'Produkte', 'Produits'],
        'mobile.entity.work_orders' => ['Ordini di lavoro', 'Work orders', 'Arbeitsaufträge', 'Ordres de travail'],
        'mobile.entity.appointments' => ['Appuntamenti', 'Appointments', 'Termine', 'Rendez-vous'],
        'mobile.entity.expenses' => ['Note spese', 'Expenses', 'Spesen', 'Notes de frais'],
        'mobile.entity.employee_shifts' => ['Turni', 'Shifts', 'Schichten', 'Équipes'],
        'mobile.entity.payslip_documents' => ['Cedolini', 'Payslips', 'Lohnabrechnungen', 'Fiches de paie'],
        'mobile.entity.time_entries' => ['Timbrature', 'Time entries', 'Zeiteinträge', 'Pointages'],
        'mobile.entity.approval_decisions' => ['Approvazioni', 'Approvals', 'Genehmigungen', 'Approbations'],
        'mobile.entity.payslip_read_receipts' => ['Cedolini letti', 'Payslip read receipts', 'Gelesene Abrechnungen', 'Fiches lues'],
        'mobile.field.company_name' => ['Ragione sociale', 'Company name', 'Firmenname', 'Raison sociale'],
        'mobile.field.first_name' => ['Nome', 'First name', 'Vorname', 'Prénom'],
        'mobile.field.last_name' => ['Cognome', 'Last name', 'Nachname', 'Nom'],
        'mobile.field.email' => ['Email', 'Email', 'E-Mail', 'E-mail'],
        'mobile.field.phone' => ['Telefono', 'Phone', 'Telefon', 'Téléphone'],
        'mobile.field.type' => ['Tipo', 'Type', 'Typ', 'Type'],
        'mobile.field.legal_type' => ['Forma giuridica', 'Legal type', 'Rechtsform', 'Forme juridique'],
        'mobile.field.country' => ['Paese', 'Country', 'Land', 'Pays'],
        'mobile.field.sku' => ['Codice articolo', 'SKU', 'Artikelnummer', 'Référence'],
        'mobile.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
        'mobile.field.unit_measure' => ['Unità di misura', 'Unit of measure', 'Masseinheit', 'Unité de mesure'],
        'mobile.field.cost_price' => ['Prezzo di costo', 'Cost price', 'Einstandspreis', 'Prix de revient'],
        'mobile.field.sale_price' => ['Prezzo di vendita', 'Sale price', 'Verkaufspreis', 'Prix de vente'],
        'mobile.field.tax_rate' => ['Aliquota IVA', 'Tax rate', 'Steuersatz', 'Taux de TVA'],
        'mobile.field.client_id' => ['Cliente', 'Customer', 'Kunde', 'Client'],
        'mobile.field.date' => ['Data', 'Date', 'Datum', 'Date'],
        'mobile.field.intervention_address' => ['Indirizzo intervento', 'Intervention address', 'Einsatzadresse', 'Adresse d\'intervention'],
        'mobile.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
        'mobile.field.line_description' => ['Descrizione riga', 'Line description', 'Positionsbeschreibung', 'Description de la ligne'],
        'mobile.field.line_quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
        'mobile.field.line_unit_price' => ['Prezzo unitario', 'Unit price', 'Stückpreis', 'Prix unitaire'],
        'mobile.field.line_tax_rate' => ['IVA riga', 'Line tax rate', 'Steuersatz Position', 'TVA de la ligne'],
        'mobile.field.title' => ['Titolo', 'Title', 'Titel', 'Titre'],
        'mobile.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
        'mobile.field.starts_at' => ['Inizio', 'Starts', 'Beginn', 'Début'],
        'mobile.field.ends_at' => ['Fine', 'Ends', 'Ende', 'Fin'],
        'mobile.field.address' => ['Indirizzo', 'Address', 'Adresse', 'Adresse'],
        'mobile.field.merchant_name' => ['Esercente', 'Merchant', 'Händler', 'Commerçant'],
        'mobile.field.amount_gross' => ['Importo lordo', 'Gross amount', 'Bruttobetrag', 'Montant brut'],
        'mobile.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
        'mobile.clock.in' => ['Timbra entrata', 'Clock in', 'Einstempeln', 'Pointer l\'arrivée'],
        'mobile.clock.out' => ['Timbra uscita', 'Clock out', 'Ausstempeln', 'Pointer le départ'],
        'mobile.clock.queued' => ['Timbratura registrata, verrà inviata appena online.', 'Punch recorded, it will be sent as soon as you are online.', 'Stempelung erfasst, wird gesendet, sobald Sie online sind.', 'Pointage enregistré, il sera envoyé dès que vous serez en ligne.'],
        'mobile.approvals.approve' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
        'mobile.approvals.reject' => ['Rifiuta', 'Reject', 'Ablehnen', 'Rejeter'],
        'mobile.approvals.empty' => ['Nessuna approvazione in sospeso', 'No pending approvals', 'Keine offenen Genehmigungen', 'Aucune approbation en attente'],
        'mobile.approvals.offline' => ['Impossibile caricare le approvazioni offline', 'Approvals cannot be loaded offline', 'Genehmigungen können offline nicht geladen werden', 'Impossible de charger les approbations hors ligne'],
        'mobile.conflicts.title' => ['Sincronizzazione e conflitti', 'Sync issues and conflicts', 'Synchronisierungsprobleme und Konflikte', 'Problèmes de synchronisation et conflits'],
        'mobile.conflicts.none' => ['Nessun problema di sincronizzazione', 'No sync issues', 'Keine Synchronisierungsprobleme', 'Aucun problème de synchronisation'],
        'mobile.conflicts.keepMine' => ['Mantieni la mia modifica', 'Keep my change', 'Meine Änderung behalten', 'Garder ma modification'],
        'mobile.conflicts.useServer' => ['Usa la versione del server', 'Use server version', 'Serverversion verwenden', 'Utiliser la version du serveur'],
        'mobile.payslip.markRead' => ['Segna come letto', 'Mark as read', 'Als gelesen markieren', 'Marquer comme lu'],
        'mobile.tenant.none' => ['Impossibile caricare le aziende', 'Companies cannot be loaded', 'Firmen können nicht geladen werden', 'Impossible de charger les entreprises'],
    ];

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = self::LOCALES[$locale] ?? null;
        if ($index === null) {
            return [];
        }

        return array_map(static fn (array $texts): string => $texts[$index], self::TEXTS);
    }
}
