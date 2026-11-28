<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the dairy and cheese manufacturing vertical (Mod 298): raw milk intake and laboratory quality, pricing grids and
 * monthly settlements, vat (kettle) batches, aging cellar with the rack map, dual-unit inventory, care scheduler and weight loss
 * curves, DBMilch.ch / TSM and SIAN milk register filings and the HACCP bidirectional recall tracer, plus the enumeration
 * labels (`DAIRY.ENUM.<ENUM>.<VALUE>`) and the codes the API reports (`DAIRY.ERROR.<CODE>`, `DAIRY.FLAG.<CODE>`).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal and regulatory acronyms (SIAN, DBMilch.ch, TSM, DOP, AOP, IGP, TVD, BDM, ASL, CUAA, HACCP, TBC, SCC, pH) stay untranslated.
 */
final class DairyCheeseFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            ...self::navigation(),
            ...self::common(),
            ...self::fields(),
            ...self::intakeAndLab(),
            ...self::pricingAndSettlements(),
            ...self::vatBatches(),
            ...self::cellar(),
            ...self::compliance(),
            ...self::enums(),
            ...self::codes(),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.DAIRY_CHEESE' => ['Caseificio e latte', 'Dairy and cheese', 'Käserei und Milch', 'Laiterie et fromagerie'],
            'NAV.DAIRY_CHEESE_DASHBOARD' => ['Cruscotto caseificio', 'Dairy dashboard', 'Käserei-Dashboard', 'Tableau de bord laiterie'],
            'NAV.DAIRY_CHEESE_COLLECTION' => ['Conferimento latte e analisi', 'Milk collection and lab', 'Milchannahme und Analysen', 'Collecte du lait et analyses'],
            'NAV.DAIRY_CHEESE_VAT_BATCHES' => ['Lotti di caldaia', 'Vat batches', 'Kessi-Chargen', 'Lots de cuve'],
            'NAV.DAIRY_CHEESE_CELLAR' => ['Magazzino di stagionatura', 'Aging cellar', 'Reifekeller', 'Cave d’affinage'],
            'NAV.DAIRY_CHEESE_COMPLIANCE' => ['SIAN / DBMilch.ch e rintracciabilità', 'SIAN / DBMilch.ch and traceability', 'SIAN / DBMilch.ch und Rückverfolgbarkeit', 'SIAN / DBMilch.ch et traçabilité'],
            'PAGE_TITLE.DAIRY_DASHBOARD' => ['Caseificio: conferimenti, produzione e stagionatura', 'Dairy: intake, production and aging', 'Käserei: Milchannahme, Produktion und Reifung', 'Laiterie : collecte, production et affinage'],
            'PAGE_TITLE.DAIRY_COLLECTION' => ['Conferimento latte, analisi e liquidazione allevatori', 'Milk collection, analyses and breeder settlement', 'Milchannahme, Analysen und Produzentenabrechnung', 'Collecte du lait, analyses et décompte des producteurs'],
            'PAGE_TITLE.DAIRY_VAT_BATCHES' => ['Lotti di caldaia e cotte', 'Vat batches and cooks', 'Kessi-Chargen und Käsungen', 'Lots de cuve et cuissons'],
            'PAGE_TITLE.DAIRY_CELLAR' => ['Stagionatura, scalere e inventario forme', 'Aging, racks and wheel inventory', 'Reifung, Gestelle und Laibinventar', 'Affinage, étagères et inventaire des meules'],
            'PAGE_TITLE.DAIRY_COMPLIANCE' => ['Adempimenti SIAN e DBMilch.ch, rintracciabilità HACCP', 'SIAN and DBMilch.ch filings, HACCP traceability', 'SIAN- und DBMilch.ch-Meldungen, HACCP-Rückverfolgbarkeit', 'Déclarations SIAN et DBMilch.ch, traçabilité HACCP'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function common(): array
    {
        return [
            'DAIRY.ACTION.ACTIVE' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'DAIRY.ACTION.INACTIVE' => ['Non attivo', 'Inactive', 'Inaktiv', 'Inactif'],
            'DAIRY.ACTION.ADD_ROW' => ['Aggiungi riga', 'Add row', 'Zeile hinzufügen', 'Ajouter une ligne'],
            'DAIRY.ACTION.ALL' => ['Tutti', 'All', 'Alle', 'Tous'],
            'DAIRY.ACTION.BACK' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'DAIRY.ACTION.CANCEL' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'DAIRY.ACTION.CLOSE' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
            'DAIRY.ACTION.DELETE' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'DAIRY.ACTION.DETAIL' => ['Dettaglio', 'Detail', 'Detail', 'Détail'],
            'DAIRY.ACTION.EDIT' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'DAIRY.ACTION.NEXT' => ['Avanti', 'Next', 'Weiter', 'Suivant'],
            'DAIRY.ACTION.REMOVE' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'DAIRY.ACTION.RESET' => ['Ricomincia', 'Start over', 'Neu beginnen', 'Recommencer'],
            'DAIRY.ACTION.SAVE' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'DAIRY.MSG.SAVED' => ['Salvato.', 'Saved.', 'Gespeichert.', 'Enregistré.'],
            'DAIRY.MSG.DELETED' => ['Eliminato.', 'Deleted.', 'Gelöscht.', 'Supprimé.'],
            'DAIRY.MSG.READ_ONLY' => ['Hai accesso in sola lettura: la registrazione non è disponibile per il tuo ruolo.', 'You have read-only access: recording is not available for your role.', 'Sie haben nur Lesezugriff: Das Erfassen ist für Ihre Rolle nicht verfügbar.', 'Vous avez un accès en lecture seule : la saisie n’est pas disponible pour votre rôle.'],
            'DAIRY.TAB.INTAKE' => ['Conferimenti', 'Intake', 'Milchannahme', 'Collecte'],
            'DAIRY.TAB.LAB' => ['Analisi di laboratorio', 'Laboratory', 'Laboranalysen', 'Laboratoire'],
            'DAIRY.TAB.PRICING' => ['Griglie premi e penalità', 'Pricing grids', 'Preisraster', 'Grilles de prix'],
            'DAIRY.TAB.SETTLEMENTS' => ['Liquidazione mensile', 'Monthly settlement', 'Monatsabrechnung', 'Décompte mensuel'],
            'DAIRY.TAB.REGISTRY' => ['Allevatori e cisterne', 'Breeders and tanks', 'Produzenten und Tanks', 'Producteurs et cuves'],
            'DAIRY.TAB.RACK_MAP' => ['Mappa scalere', 'Rack map', 'Gestellkarte', 'Plan des étagères'],
            'DAIRY.TAB.WHEELS' => ['Inventario forme', 'Wheel inventory', 'Laibinventar', 'Inventaire des meules'],
            'DAIRY.TAB.CARE' => ['Cure di stagionatura', 'Care operations', 'Pflegearbeiten', 'Soins d’affinage'],
            'DAIRY.TAB.CURVES' => ['Curve di calo peso', 'Weight loss curves', 'Gewichtsverlustkurven', 'Courbes de perte de poids'],
            'DAIRY.TAB.DBMILCH' => ['DBMilch.ch / TSM (CH)', 'DBMilch.ch / TSM (CH)', 'DBMilch.ch / TSM (CH)', 'DBMilch.ch / TSM (CH)'],
            'DAIRY.TAB.SIAN' => ['SIAN Registro telematico latte (IT)', 'SIAN telematic milk register (IT)', 'SIAN Telematisches Milchregister (IT)', 'SIAN Registre télématique du lait (IT)'],
            'DAIRY.TAB.TRACE' => ['Rintracciabilità HACCP', 'HACCP traceability', 'HACCP-Rückverfolgbarkeit', 'Traçabilité HACCP'],
            'DAIRY.KPI.MILK_TODAY_LITERS' => ['Latte conferito oggi (litri)', 'Milk received today (liters)', 'Heute angelieferte Milch (Liter)', 'Lait reçu aujourd’hui (litres)'],
            'DAIRY.KPI.DELIVERIES_TODAY' => ['Conferimenti di oggi', 'Deliveries today', 'Anlieferungen heute', 'Livraisons du jour'],
            'DAIRY.KPI.AVG_FAT' => ['Grasso medio (%)', 'Average fat (%)', 'Durchschnittliches Fett (%)', 'Matière grasse moyenne (%)'],
            'DAIRY.KPI.AVG_PROTEIN' => ['Proteine medie (%)', 'Average protein (%)', 'Durchschnittliches Eiweiss (%)', 'Protéines moyennes (%)'],
            'DAIRY.KPI.AVG_SCC' => ['Cellule somatiche medie (SCC)', 'Average somatic cells (SCC)', 'Durchschnittliche Zellzahl (SCC)', 'Cellules somatiques moyennes (SCC)'],
            'DAIRY.KPI.ACTIVE_VAT_BATCHES' => ['Lotti di caldaia attivi', 'Active vat batches', 'Aktive Kessi-Chargen', 'Lots de cuve actifs'],
            'DAIRY.KPI.CELLAR_WHEELS' => ['Forme in cantina', 'Wheels in the cellar', 'Laibe im Keller', 'Meules en cave'],
            'DAIRY.KPI.OCCUPANCY' => ['Occupazione della cantina', 'Cellar occupancy', 'Kellerbelegung', 'Occupation de la cave'],
            'DAIRY.KPI.WHEELS' => ['forme', 'wheels', 'Laibe', 'meules'],
            'DAIRY.DASHBOARD.INHIBITOR_ALERT' => ['Inibitori positivi rilevati', 'Positive inhibitors detected', 'Positive Hemmstoffe festgestellt', 'Inhibiteurs positifs détectés'],
            'DAIRY.DASHBOARD.INHIBITOR_ALERT_DETAIL' => ['{count} analisi con presenza di inibitori o antibiotici: il latte è in quarantena.', '{count} analyses with inhibitors or antibiotics: the milk is quarantined.', '{count} Analysen mit Hemmstoffen oder Antibiotika: Die Milch ist gesperrt.', '{count} analyses avec inhibiteurs ou antibiotiques : le lait est mis en quarantaine.'],
            'DAIRY.DASHBOARD.TRENDS' => ['Andamento ultimi 30 giorni', 'Last 30 days trend', 'Verlauf der letzten 30 Tage', 'Évolution des 30 derniers jours'],
            'DAIRY.DASHBOARD.UPCOMING_CARE' => ['Prossime cure di stagionatura', 'Upcoming care operations', 'Anstehende Pflegearbeiten', 'Prochains soins d’affinage'],
            'DAIRY.DASHBOARD.NO_CARE' => ['Nessuna cura in programma.', 'No care operation scheduled.', 'Keine Pflegearbeit geplant.', 'Aucun soin programmé.'],
            'DAIRY.DASHBOARD.OVERDUE_COUNT' => ['{count} in ritardo', '{count} overdue', '{count} überfällig', '{count} en retard'],
            'DAIRY.DASHBOARD.DEADLINES' => ['Scadenze SIAN e DBMilch.ch', 'SIAN and DBMilch.ch deadlines', 'Fristen SIAN und DBMilch.ch', 'Échéances SIAN et DBMilch.ch'],
            'DAIRY.DASHBOARD.NO_DEADLINES' => ['Nessuna scadenza imminente.', 'No upcoming deadline.', 'Keine anstehende Frist.', 'Aucune échéance proche.'],
            'DAIRY.DASHBOARD.DAYS_LEFT' => ['{days} giorni', '{days} days', '{days} Tage', '{days} jours'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'DAIRY.FIELD.ADJUSTMENT_PER_KG' => ['Premio / penalità per kg', 'Bonus / penalty per kg', 'Zuschlag / Abzug pro kg', 'Bonus / pénalité par kg'],
            'DAIRY.FIELD.AGE' => ['Età', 'Age', 'Alter', 'Âge'],
            'DAIRY.FIELD.AGE_MONTHS' => ['Età (mesi)', 'Age (months)', 'Alter (Monate)', 'Âge (mois)'],
            'DAIRY.FIELD.ASL' => ['Codice ASL', 'ASL code', 'ASL-Code', 'Code ASL'],
            'DAIRY.FIELD.AUTHORITY' => ['Ente', 'Authority', 'Behörde', 'Autorité'],
            'DAIRY.FIELD.AVG_FAT' => ['Grasso medio %', 'Avg. fat %', 'Ø Fett %', 'MG moy. %'],
            'DAIRY.FIELD.AVG_PROTEIN' => ['Proteine medie %', 'Avg. protein %', 'Ø Eiweiss %', 'Protéines moy. %'],
            'DAIRY.FIELD.BASE_PRICE' => ['Prezzo base per kg', 'Base price per kg', 'Basispreis pro kg', 'Prix de base par kg'],
            'DAIRY.FIELD.BDM' => ['Numero BDM', 'BDM number', 'BDM-Nummer', 'Numéro BDM'],
            'DAIRY.FIELD.BOOK_KG' => ['Peso a libro (kg)', 'Book weight (kg)', 'Buchgewicht (kg)', 'Poids comptable (kg)'],
            'DAIRY.FIELD.BREEDER' => ['Allevatore', 'Breeder', 'Produzent', 'Producteur'],
            'DAIRY.FIELD.BREEDERS' => ['Allevatori', 'Breeders', 'Produzenten', 'Producteurs'],
            'DAIRY.FIELD.CAPACITY' => ['Capacità (litri)', 'Capacity (liters)', 'Kapazität (Liter)', 'Capacité (litres)'],
            'DAIRY.FIELD.CAPACITY_WHEELS' => ['Capacità (forme)', 'Capacity (wheels)', 'Kapazität (Laibe)', 'Capacité (meules)'],
            'DAIRY.FIELD.CARE_TYPE' => ['Tipo di cura', 'Care type', 'Pflegeart', 'Type de soin'],
            'DAIRY.FIELD.CASEIN' => ['Caseina %', 'Casein %', 'Kasein %', 'Caséine %'],
            'DAIRY.FIELD.CHEESE_MILK_SUPPLEMENT' => ['Supplemento latte trasformato in formaggio per kg', 'Cheese-milk supplement per kg', 'Verkäsungszulage pro kg', 'Supplément de transformation fromagère par kg'],
            'DAIRY.FIELD.CHEESE_TYPE' => ['Tipo di formaggio', 'Cheese type', 'Käsesorte', 'Type de fromage'],
            'DAIRY.FIELD.CODE' => ['Codice', 'Code', 'Code', 'Code'],
            'DAIRY.FIELD.COLLECTION_DATE' => ['Data di raccolta', 'Collection date', 'Sammeldatum', 'Date de collecte'],
            'DAIRY.FIELD.COMPONENT' => ['Componente', 'Component', 'Komponente', 'Composant'],
            'DAIRY.FIELD.CUAA' => ['CUAA', 'CUAA', 'CUAA', 'CUAA'],
            'DAIRY.FIELD.CURD_YIELD' => ['Resa in cagliata (kg)', 'Curd yield (kg)', 'Bruchausbeute (kg)', 'Rendement en caillé (kg)'],
            'DAIRY.FIELD.CURRENT_KG' => ['Peso attuale (kg)', 'Current weight (kg)', 'Aktuelles Gewicht (kg)', 'Poids actuel (kg)'],
            'DAIRY.FIELD.CUSTOMER' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'DAIRY.FIELD.DATE' => ['Data', 'Date', 'Datum', 'Date'],
            'DAIRY.FIELD.DATE_FROM' => ['Dal', 'From', 'Von', 'Du'],
            'DAIRY.FIELD.DATE_TO' => ['Al', 'To', 'Bis', 'Au'],
            'DAIRY.FIELD.DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'DAIRY.FIELD.DIRECTION' => ['Direzione', 'Direction', 'Richtung', 'Direction'],
            'DAIRY.FIELD.DOP_PREMIUM' => ['Premio DOP / AOP per kg', 'DOP / AOP premium per kg', 'DOP- / AOP-Zuschlag pro kg', 'Prime AOP / DOP par kg'],
            'DAIRY.FIELD.DUE_DATE' => ['Scadenza', 'Due date', 'Fälligkeit', 'Échéance'],
            'DAIRY.FIELD.EXECUTED_AT' => ['Eseguita il', 'Executed on', 'Ausgeführt am', 'Exécuté le'],
            'DAIRY.FIELD.FARM_CODE' => ['Codice stalla', 'Farm code', 'Betriebscode', 'Code d’exploitation'],
            'DAIRY.FIELD.FAT' => ['Grasso %', 'Fat %', 'Fett %', 'Matière grasse %'],
            'DAIRY.FIELD.FILE' => ['File', 'File', 'Datei', 'Fichier'],
            'DAIRY.FIELD.FILL' => ['Riempimento', 'Fill level', 'Füllstand', 'Remplissage'],
            'DAIRY.FIELD.FINAL_YIELD_PCT' => ['Resa finale %', 'Final yield %', 'Endausbeute %', 'Rendement final %'],
            'DAIRY.FIELD.FLAGS' => ['Anomalie', 'Anomalies', 'Auffälligkeiten', 'Anomalies'],
            'DAIRY.FIELD.FORMAT' => ['Formato', 'Format', 'Format', 'Format'],
            'DAIRY.FIELD.FREEZING_POINT' => ['Punto crioscopico (°C)', 'Freezing point (°C)', 'Gefrierpunkt (°C)', 'Point cryoscopique (°C)'],
            'DAIRY.FIELD.FRESH_YIELD_24H' => ['Resa casearia a 24 ore (kg)', '24 hour fresh yield (kg)', 'Frischausbeute nach 24 Stunden (kg)', 'Rendement frais à 24 heures (kg)'],
            'DAIRY.FIELD.FROM_VALUE' => ['Da valore', 'From value', 'Ab Wert', 'À partir de'],
            'DAIRY.FIELD.GENERATED_AT' => ['Generato il', 'Generated on', 'Erstellt am', 'Généré le'],
            'DAIRY.FIELD.GRADE' => ['Categoria', 'Grade', 'Klasse', 'Catégorie'],
            'DAIRY.FIELD.IDENTIFIERS' => ['Identificativi', 'Identifiers', 'Kennungen', 'Identifiants'],
            'DAIRY.FIELD.IMPORT_FORMAT' => ['Formato del laboratorio', 'Laboratory format', 'Laborformat', 'Format du laboratoire'],
            'DAIRY.FIELD.INHIBITORS' => ['Inibitori', 'Inhibitors', 'Hemmstoffe', 'Inhibiteurs'],
            'DAIRY.FIELD.INITIAL_KG' => ['Peso iniziale (kg)', 'Initial weight (kg)', 'Anfangsgewicht (kg)', 'Poids initial (kg)'],
            'DAIRY.FIELD.INITIAL_WEIGHT_EACH' => ['Peso iniziale per forma (kg)', 'Initial weight per wheel (kg)', 'Anfangsgewicht pro Laib (kg)', 'Poids initial par meule (kg)'],
            'DAIRY.FIELD.INVOICE_REF' => ['Riferimento fattura', 'Invoice reference', 'Rechnungsreferenz', 'Référence de facture'],
            'DAIRY.FIELD.JURISDICTION' => ['Paese', 'Country', 'Land', 'Pays'],
            'DAIRY.FIELD.KG' => ['Kg', 'Kg', 'Kg', 'Kg'],
            'DAIRY.FIELD.LABORATORY' => ['Laboratorio', 'Laboratory', 'Labor', 'Laboratoire'],
            'DAIRY.FIELD.LAST_CLEANED' => ['Ultima pulizia', 'Last cleaned', 'Zuletzt gereinigt', 'Dernier nettoyage'],
            'DAIRY.FIELD.LEVEL' => ['Livello', 'Level', 'Ebene', 'Niveau'],
            'DAIRY.FIELD.LITERS' => ['Litri', 'Liters', 'Liter', 'Litres'],
            'DAIRY.FIELD.LOSS_PCT' => ['Calo peso % (reale / teorico)', 'Weight loss % (actual / theoretical)', 'Gewichtsverlust % (effektiv / theoretisch)', 'Perte de poids % (réelle / théorique)'],
            'DAIRY.FIELD.MILK_KG' => ['Latte (kg)', 'Milk (kg)', 'Milch (kg)', 'Lait (kg)'],
            'DAIRY.FIELD.MOVEMENT_TYPE' => ['Tipo di movimento', 'Movement type', 'Bewegungsart', 'Type de mouvement'],
            'DAIRY.FIELD.MUNICIPALITY' => ['Comune', 'Municipality', 'Gemeinde', 'Commune'],
            'DAIRY.FIELD.NAME' => ['Nome', 'Name', 'Name', 'Nom'],
            'DAIRY.FIELD.NOTES' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'DAIRY.FIELD.OVERDUE' => ['In ritardo', 'Overdue', 'Überfällig', 'En retard'],
            'DAIRY.FIELD.PERIOD' => ['Periodo', 'Period', 'Periode', 'Période'],
            'DAIRY.FIELD.PERIOD_END' => ['Fine periodo', 'Period end', 'Periodenende', 'Fin de période'],
            'DAIRY.FIELD.PERIOD_START' => ['Inizio periodo', 'Period start', 'Periodenbeginn', 'Début de période'],
            'DAIRY.FIELD.PLAQUE_FROM' => ['Placca dal numero', 'Plaque from number', 'Platte ab Nummer', 'Plaque à partir du numéro'],
            'DAIRY.FIELD.PLAQUE_PREFIX' => ['Prefisso placca di caseina', 'Casein plaque prefix', 'Präfix der Kaseinplatte', 'Préfixe de la plaque de caséine'],
            'DAIRY.FIELD.PLAQUE_RANGE' => ['Intervallo placche', 'Plaque range', 'Plattenbereich', 'Plage de plaques'],
            'DAIRY.FIELD.PLAQUE_TO' => ['Placca fino al numero', 'Plaque up to number', 'Platte bis Nummer', 'Plaque jusqu’au numéro'],
            'DAIRY.FIELD.POSITION' => ['Posizione', 'Position', 'Position', 'Position'],
            'DAIRY.FIELD.PRICING_GRID' => ['Griglia prezzi', 'Pricing grid', 'Preisraster', 'Grille de prix'],
            'DAIRY.FIELD.PRODUCTION_DATE' => ['Data di produzione', 'Production date', 'Produktionsdatum', 'Date de production'],
            'DAIRY.FIELD.PROTEIN' => ['Proteine %', 'Protein %', 'Eiweiss %', 'Protéines %'],
            'DAIRY.FIELD.RACK' => ['Scalera', 'Rack', 'Gestell', 'Étagère'],
            'DAIRY.FIELD.RECALL_REASON' => ['Motivo del ritiro', 'Recall reason', 'Rückrufgrund', 'Motif du rappel'],
            'DAIRY.FIELD.RECORDS' => ['Registrazioni', 'Records', 'Datensätze', 'Enregistrements'],
            'DAIRY.FIELD.RECORD_ID' => ['Identificativo del record', 'Record identifier', 'Datensatz-Kennung', 'Identifiant de l’enregistrement'],
            'DAIRY.FIELD.REFERENCE' => ['Riferimento', 'Reference', 'Referenz', 'Référence'],
            'DAIRY.FIELD.REGION' => ['Cantone / provincia', 'Canton / province', 'Kanton / Provinz', 'Canton / province'],
            'DAIRY.FIELD.RENNET_ML' => ['Caglio (ml)', 'Rennet (ml)', 'Lab (ml)', 'Présure (ml)'],
            'DAIRY.FIELD.RENNET_TYPE' => ['Tipo di caglio', 'Rennet type', 'Labart', 'Type de présure'],
            'DAIRY.FIELD.ROOM' => ['Locale', 'Room', 'Raum', 'Salle'],
            'DAIRY.FIELD.ROUTE_CODE' => ['Giro di raccolta', 'Collection route', 'Sammeltour', 'Tournée de collecte'],
            'DAIRY.FIELD.SAMPLE_DATE' => ['Data del campione', 'Sample date', 'Probedatum', 'Date de l’échantillon'],
            'DAIRY.FIELD.SCC' => ['Cellule somatiche (SCC/ml)', 'Somatic cells (SCC/ml)', 'Zellzahl (SCC/ml)', 'Cellules somatiques (SCC/ml)'],
            'DAIRY.FIELD.SCHEDULED_FOR' => ['Programmata per', 'Scheduled for', 'Geplant für', 'Prévu pour'],
            'DAIRY.FIELD.SCOPE' => ['Ambito', 'Scope', 'Umfang', 'Portée'],
            'DAIRY.FIELD.SEARCH' => ['Cerca matricola o lotto', 'Search serial or batch', 'Seriennummer oder Charge suchen', 'Rechercher matricule ou lot'],
            'DAIRY.FIELD.SERIAL' => ['Matricola (placca)', 'Serial (plaque)', 'Seriennummer (Platte)', 'Matricule (plaque)'],
            'DAIRY.FIELD.SERIAL_OR_ID' => ['Matricola della placca o identificativo', 'Plaque serial or identifier', 'Seriennummer der Platte oder Kennung', 'Matricule de la plaque ou identifiant'],
            'DAIRY.FIELD.SESSION' => ['Sessione', 'Session', 'Melkzeit', 'Traite'],
            'DAIRY.FIELD.SHELF' => ['Asse', 'Shelf', 'Brett', 'Planche'],
            'DAIRY.FIELD.STARTER_AMOUNT' => ['Quantità di sieroinnesto', 'Starter amount', 'Menge Kulturen', 'Quantité de ferments'],
            'DAIRY.FIELD.STARTER_CULTURE' => ['Sieroinnesto', 'Starter culture', 'Kulturen', 'Ferments'],
            'DAIRY.FIELD.STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'DAIRY.FIELD.TANK' => ['Cisterna', 'Tank', 'Tank', 'Cuve'],
            'DAIRY.FIELD.TANKER_PLATE' => ['Targa autocisterna', 'Tanker plate', 'Kennzeichen Tankwagen', 'Plaque du camion-citerne'],
            'DAIRY.FIELD.TARGET' => ['Destinazione', 'Target', 'Ziel', 'Cible'],
            'DAIRY.FIELD.TBC' => ['Carica batterica (UFC/ml)', 'Bacterial count (CFU/ml)', 'Keimzahl (KbE/ml)', 'Charge bactérienne (UFC/ml)'],
            'DAIRY.FIELD.TEMPERATURE' => ['Temperatura (°C)', 'Temperature (°C)', 'Temperatur (°C)', 'Température (°C)'],
            'DAIRY.FIELD.THEORETICAL_KG' => ['Peso teorico (kg)', 'Theoretical weight (kg)', 'Theoretisches Gewicht (kg)', 'Poids théorique (kg)'],
            'DAIRY.FIELD.TIERS' => ['Scaglioni', 'Tiers', 'Stufen', 'Paliers'],
            'DAIRY.FIELD.TIME' => ['Ora', 'Time', 'Zeit', 'Heure'],
            'DAIRY.FIELD.TOTAL' => ['Totale', 'Total', 'Total', 'Total'],
            'DAIRY.FIELD.TO_VALUE' => ['A valore', 'To value', 'Bis Wert', 'Jusqu’à'],
            'DAIRY.FIELD.TRACE_SOURCE' => ['Cerca per', 'Search by', 'Suchen nach', 'Rechercher par'],
            'DAIRY.FIELD.TVD' => ['Numero TVD', 'TVD number', 'TVD-Nummer', 'Numéro BDTA'],
            'DAIRY.FIELD.UREA' => ['Urea (mg/l)', 'Urea (mg/l)', 'Harnstoff (mg/l)', 'Urée (mg/l)'],
            'DAIRY.FIELD.VALID_FROM' => ['Valida dal', 'Valid from', 'Gültig ab', 'Valable dès'],
            'DAIRY.FIELD.VALID_TO' => ['Valida fino al', 'Valid until', 'Gültig bis', 'Valable jusqu’au'],
            'DAIRY.FIELD.VARIANCE_KG' => ['Differenza (kg)', 'Variance (kg)', 'Differenz (kg)', 'Écart (kg)'],
            'DAIRY.FIELD.VAT_BATCH' => ['Lotto di caldaia', 'Vat batch', 'Kessi-Charge', 'Lot de cuve'],
            'DAIRY.FIELD.VAT_NUMBER' => ['Numero di caldaia / cotta', 'Vat / cook number', 'Kessi- / Käsungsnummer', 'Numéro de cuve / cuisson'],
            'DAIRY.FIELD.WEIGHED_KG' => ['Peso rilevato (kg)', 'Weighed (kg)', 'Gewogen (kg)', 'Pesé (kg)'],
            'DAIRY.FIELD.WEIGHT_KG' => ['Peso (kg)', 'Weight (kg)', 'Gewicht (kg)', 'Poids (kg)'],
            'DAIRY.FIELD.WHEELS' => ['Forme', 'Wheels', 'Laibe', 'Meules'],
            'DAIRY.FIELD.WHEELS_AFFECTED' => ['Forme coinvolte', 'Wheels affected', 'Betroffene Laibe', 'Meules concernées'],
            'DAIRY.FIELD.YIELD_24H_PCT' => ['Resa 24 ore %', '24 hour yield %', 'Ausbeute 24 Std. %', 'Rendement 24 h %'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function intakeAndLab(): array
    {
        return [
            'DAIRY.INTAKE.QUICK_ENTRY' => ['Inserimento rapido conferimenti', 'Quick intake entry', 'Schnellerfassung der Anlieferungen', 'Saisie rapide des livraisons'],
            'DAIRY.INTAKE.SAVE_ROWS' => ['Registra {count} conferimenti', 'Register {count} deliveries', '{count} Anlieferungen erfassen', 'Enregistrer {count} livraisons'],
            'DAIRY.INTAKE.REJECTED' => ['{count} righe rifiutate', '{count} rows rejected', '{count} Zeilen abgelehnt', '{count} lignes refusées'],
            'DAIRY.INTAKE.LEDGER' => ['Registro dei conferimenti', 'Intake ledger', 'Anlieferungsjournal', 'Journal des livraisons'],
            'DAIRY.INTAKE.CONFIRM_DELETE' => ['Eliminare questo conferimento? Il volume sarà tolto dalla cisterna.', 'Delete this delivery? Its volume will be taken out of the tank.', 'Diese Anlieferung löschen? Das Volumen wird dem Tank entnommen.', 'Supprimer cette livraison ? Son volume sera retiré de la cuve.'],
            'DAIRY.LAB.IMPORT_WIZARD' => ['Importazione analisi di laboratorio', 'Laboratory analysis import', 'Import der Laboranalysen', 'Import des analyses de laboratoire'],
            'DAIRY.LAB.STEP_FILE' => ['File', 'File', 'Datei', 'Fichier'],
            'DAIRY.LAB.STEP_PREVIEW' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'DAIRY.LAB.STEP_DONE' => ['Importato', 'Imported', 'Importiert', 'Importé'],
            'DAIRY.LAB.PREVIEW' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'DAIRY.LAB.PREVIEW_READY' => ['Anteprima pronta.', 'Preview ready.', 'Vorschau bereit.', 'Aperçu prêt.'],
            'DAIRY.LAB.COMMIT' => ['Importa le righe valide', 'Import the valid rows', 'Gültige Zeilen importieren', 'Importer les lignes valides'],
            'DAIRY.LAB.IMPORTED' => ['Analisi importate.', 'Analyses imported.', 'Analysen importiert.', 'Analyses importées.'],
            'DAIRY.LAB.TOTAL_ROWS' => ['{count} righe', '{count} rows', '{count} Zeilen', '{count} lignes'],
            'DAIRY.LAB.VALID_ROWS' => ['{count} valide', '{count} valid', '{count} gültig', '{count} valides'],
            'DAIRY.LAB.ANOMALOUS_ROWS' => ['{count} con anomalie', '{count} with anomalies', '{count} mit Auffälligkeiten', '{count} avec anomalies'],
            'DAIRY.LAB.REJECTED_ROWS' => ['{count} scartate', '{count} rejected', '{count} abgelehnt', '{count} rejetées'],
            'DAIRY.LAB.RESULTS' => ['Risultati di laboratorio', 'Laboratory results', 'Laborergebnisse', 'Résultats de laboratoire'],
            'DAIRY.LAB.MANUAL_ENTRY' => ['Inserimento manuale', 'Manual entry', 'Manuelle Erfassung', 'Saisie manuelle'],
            'DAIRY.LAB.LIVE_ANOMALIES' => ['Anomalie rilevate nei valori inseriti', 'Anomalies in the entered values', 'Auffälligkeiten in den erfassten Werten', 'Anomalies dans les valeurs saisies'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function pricingAndSettlements(): array
    {
        return [
            'DAIRY.PRICING.TITLE' => ['Griglie di qualità: premi, penalità e supplementi', 'Quality grids: bonuses, penalties and supplements', 'Qualitätsraster: Zuschläge, Abzüge und Zulagen', 'Grilles de qualité : bonus, pénalités et suppléments'],
            'DAIRY.PRICING.NEW' => ['Nuova griglia', 'New grid', 'Neues Raster', 'Nouvelle grille'],
            'DAIRY.PRICING.EDITOR' => ['Griglia prezzi del latte', 'Milk pricing grid', 'Milchpreisraster', 'Grille de prix du lait'],
            'DAIRY.PRICING.TIERS' => ['Scaglioni di qualità', 'Quality tiers', 'Qualitätsstufen', 'Paliers de qualité'],
            'DAIRY.PRICING.TIERS_HINT' => ['Per ogni componente indica l’intervallo di valori e il premio (positivo) o la penalità (negativa) per kg di latte.', 'For each component give the value range and the bonus (positive) or penalty (negative) per kg of milk.', 'Geben Sie pro Komponente den Wertebereich und den Zuschlag (positiv) oder Abzug (negativ) pro kg Milch an.', 'Pour chaque composant, indiquez la plage de valeurs et le bonus (positif) ou la pénalité (négative) par kg de lait.'],
            'DAIRY.PRICING.ADD_TIER' => ['Aggiungi scaglione', 'Add tier', 'Stufe hinzufügen', 'Ajouter un palier'],
            'DAIRY.PRICING.OVERLAP' => ['Gli scaglioni dello stesso componente si sovrappongono.', 'Tiers of the same component overlap.', 'Stufen derselben Komponente überschneiden sich.', 'Les paliers d’un même composant se chevauchent.'],
            'DAIRY.PRICING.CONFIRM_DELETE' => ['Eliminare questa griglia?', 'Delete this grid?', 'Dieses Raster löschen?', 'Supprimer cette grille ?'],
            'DAIRY.SETTLEMENT.GENERATE' => ['Genera la liquidazione mensile', 'Generate the monthly settlement', 'Monatsabrechnung erstellen', 'Générer le décompte mensuel'],
            'DAIRY.SETTLEMENT.GENERATE_ACTION' => ['Genera liquidazione', 'Generate settlement', 'Abrechnung erstellen', 'Générer le décompte'],
            'DAIRY.SETTLEMENT.GENERATED' => ['Liquidazione generata.', 'Settlement generated.', 'Abrechnung erstellt.', 'Décompte généré.'],
            'DAIRY.SETTLEMENT.ALL_BREEDERS' => ['Tutti gli allevatori', 'All breeders', 'Alle Produzenten', 'Tous les producteurs'],
            'DAIRY.SETTLEMENT.LIST' => ['Liquidazione per allevatore', 'Settlement per breeder', 'Abrechnung pro Produzent', 'Décompte par producteur'],
            'DAIRY.SETTLEMENT.SETTLE_READ_ONLY' => ['L’approvazione e i documenti di liquidazione sono riservati a titolari, amministratori e contabili.', 'Approval and the settlement documents are reserved to owners, administrators and accountants.', 'Genehmigung und Abrechnungsbelege sind Inhabern, Administratoren und Buchhaltern vorbehalten.', 'L’approbation et les documents de décompte sont réservés aux propriétaires, administrateurs et comptables.'],
            'DAIRY.SETTLEMENT.BASE' => ['Prezzo base', 'Base price', 'Basispreis', 'Prix de base'],
            'DAIRY.SETTLEMENT.QUALITY' => ['Premi qualità', 'Quality bonuses', 'Qualitätszuschläge', 'Bonus qualité'],
            'DAIRY.SETTLEMENT.SUPPLEMENT' => ['Supplemento formaggio / DOP', 'Cheese / DOP supplement', 'Käse- / DOP-Zulage', 'Supplément fromage / AOP'],
            'DAIRY.SETTLEMENT.PENALTY' => ['Penalità', 'Penalties', 'Abzüge', 'Pénalités'],
            'DAIRY.SETTLEMENT.NET' => ['Netto da liquidare', 'Net payout', 'Nettoauszahlung', 'Net à payer'],
            'DAIRY.SETTLEMENT.RATE' => ['Tariffa', 'Rate', 'Ansatz', 'Taux'],
            'DAIRY.SETTLEMENT.AMOUNT' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'DAIRY.SETTLEMENT.APPROVE' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'DAIRY.SETTLEMENT.APPROVED' => ['Liquidazione approvata.', 'Settlement approved.', 'Abrechnung genehmigt.', 'Décompte approuvé.'],
            'DAIRY.SETTLEMENT.DOCUMENT_CREATED' => ['Documento creato', 'Document created', 'Beleg erstellt', 'Document créé'],
            'DAIRY.SETTLEMENT.DOCUMENT_DONE' => ['Documento di liquidazione creato.', 'Settlement document created.', 'Abrechnungsbeleg erstellt.', 'Document de décompte créé.'],
            'DAIRY.SETTLEMENT.LINE.BASE' => ['Latte al prezzo base', 'Milk at the base price', 'Milch zum Basispreis', 'Lait au prix de base'],
            'DAIRY.SETTLEMENT.LINE.FAT' => ['Grasso', 'Fat', 'Fett', 'Matière grasse'],
            'DAIRY.SETTLEMENT.LINE.PROTEIN' => ['Proteine', 'Protein', 'Eiweiss', 'Protéines'],
            'DAIRY.SETTLEMENT.LINE.TBC' => ['Carica batterica', 'Bacterial count', 'Keimzahl', 'Charge bactérienne'],
            'DAIRY.SETTLEMENT.LINE.SCC' => ['Cellule somatiche', 'Somatic cells', 'Zellzahl', 'Cellules somatiques'],
            'DAIRY.SETTLEMENT.LINE.INHIBITOR' => ['Inibitori', 'Inhibitors', 'Hemmstoffe', 'Inhibiteurs'],
            'DAIRY.SETTLEMENT.LINE.FREEZING_POINT' => ['Punto crioscopico', 'Freezing point', 'Gefrierpunkt', 'Point cryoscopique'],
            'DAIRY.SETTLEMENT.LINE.CASEIN' => ['Caseina', 'Casein', 'Kasein', 'Caséine'],
            'DAIRY.SETTLEMENT.LINE.UREA' => ['Urea', 'Urea', 'Harnstoff', 'Urée'],
            'DAIRY.SETTLEMENT.LINE.CHEESE_MILK_SUPPLEMENT' => ['Supplemento latte trasformato in formaggio', 'Cheese-milk supplement', 'Verkäsungszulage', 'Supplément de transformation fromagère'],
            'DAIRY.SETTLEMENT.LINE.DOP_PREMIUM' => ['Premio DOP / AOP', 'DOP / AOP premium', 'DOP- / AOP-Zuschlag', 'Prime AOP / DOP'],
            'DAIRY.REGISTRY.BREEDERS' => ['Allevatori conferenti', 'Supplying breeders', 'Liefernde Produzenten', 'Producteurs livreurs'],
            'DAIRY.REGISTRY.NEW_BREEDER' => ['Nuovo allevatore', 'New breeder', 'Neuer Produzent', 'Nouveau producteur'],
            'DAIRY.REGISTRY.BREEDER_EDITOR' => ['Scheda allevatore', 'Breeder record', 'Produzentendaten', 'Fiche producteur'],
            'DAIRY.REGISTRY.CONFIRM_DELETE' => ['Eliminare questo allevatore?', 'Delete this breeder?', 'Diesen Produzenten löschen?', 'Supprimer ce producteur ?'],
            'DAIRY.REGISTRY.TANKS' => ['Cisterne e silos del latte', 'Milk tanks and silos', 'Milchtanks und Silos', 'Cuves et silos à lait'],
            'DAIRY.REGISTRY.NEW_TANK' => ['Nuova cisterna', 'New tank', 'Neuer Tank', 'Nouvelle cuve'],
            'DAIRY.REGISTRY.TANK_EDITOR' => ['Cisterna del latte', 'Milk tank', 'Milchtank', 'Cuve à lait'],
            'DAIRY.REGISTRY.CLEAN' => ['Registra pulizia', 'Record cleaning', 'Reinigung erfassen', 'Enregistrer le nettoyage'],
            'DAIRY.REGISTRY.CLEANED' => ['Pulizia registrata.', 'Cleaning recorded.', 'Reinigung erfasst.', 'Nettoyage enregistré.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function vatBatches(): array
    {
        return [
            'DAIRY.VAT.LIST' => ['Lotti di caldaia e cotte', 'Vat batches and cooks', 'Kessi-Chargen und Käsungen', 'Lots de cuve et cuissons'],
            'DAIRY.VAT.NEW' => ['Nuova cotta', 'New cook', 'Neue Käsung', 'Nouvelle cuisson'],
            'DAIRY.VAT.CONTINUE' => ['Prosegui', 'Continue', 'Fortsetzen', 'Continuer'],
            'DAIRY.VAT.WIZARD_TITLE' => ['Produzione del lotto di caldaia', 'Vat batch production', 'Produktion der Kessi-Charge', 'Production du lot de cuve'],
            'DAIRY.VAT.STEP_TANKS' => ['Latte e cisterne', 'Milk and tanks', 'Milch und Tanks', 'Lait et cuves'],
            'DAIRY.VAT.STEP_PROCESS' => ['Sieroinnesto, caglio e pH', 'Starter, rennet and pH', 'Kulturen, Lab und pH', 'Ferments, présure et pH'],
            'DAIRY.VAT.STEP_PLAQUES' => ['Placche di caseina', 'Casein plaques', 'Kaseinplatten', 'Plaques de caséine'],
            'DAIRY.VAT.STEP_YIELD' => ['Resa e forme', 'Yield and wheels', 'Ausbeute und Laibe', 'Rendement et meules'],
            'DAIRY.VAT.AVAILABLE' => ['Disponibile', 'Available', 'Verfügbar', 'Disponible'],
            'DAIRY.VAT.EXCEEDS_TANK' => ['Oltre il volume della cisterna', 'Above the tank volume', 'Über dem Tankvolumen', 'Au-delà du volume de la cuve'],
            'DAIRY.VAT.ADD_TANK' => ['Aggiungi cisterna', 'Add tank', 'Tank hinzufügen', 'Ajouter une cuve'],
            'DAIRY.VAT.TOTAL_MILK' => ['Latte totale', 'Total milk', 'Milch gesamt', 'Lait total'],
            'DAIRY.VAT.CREATE_BATCH' => ['Crea il lotto e preleva il latte', 'Create the batch and draw the milk', 'Charge erstellen und Milch entnehmen', 'Créer le lot et prélever le lait'],
            'DAIRY.VAT.CREATED' => ['Lotto creato, latte prelevato dalle cisterne.', 'Batch created, milk drawn from the tanks.', 'Charge erstellt, Milch aus den Tanks entnommen.', 'Lot créé, lait prélevé dans les cuves.'],
            'DAIRY.VAT.BATCH_CREATED' => ['Lotto {code}: composizione del latte per allevatore', 'Batch {code}: milk composition by breeder', 'Charge {code}: Milchzusammensetzung nach Produzent', 'Lot {code} : composition du lait par producteur'],
            'DAIRY.VAT.PH_LOG' => ['Curva di pH e temperatura', 'pH and temperature curve', 'pH- und Temperaturverlauf', 'Courbe de pH et de température'],
            'DAIRY.VAT.ADD_PH' => ['Aggiungi rilevazione', 'Add reading', 'Messung hinzufügen', 'Ajouter un relevé'],
            'DAIRY.VAT.SAVE_PROCESS' => ['Salva e prosegui', 'Save and continue', 'Speichern und weiter', 'Enregistrer et continuer'],
            'DAIRY.VAT.NO_RACK' => ['Nessuna scalera (in salamoia)', 'No rack (in brine)', 'Kein Gestell (in der Lake)', 'Aucune étagère (en saumure)'],
            'DAIRY.VAT.WHEELS_PREVIEW' => ['{count} forme, matricole da {first} a {last}', '{count} wheels, serials {first} to {last}', '{count} Laibe, Seriennummern {first} bis {last}', '{count} meules, matricules de {first} à {last}'],
            'DAIRY.VAT.PLAQUE_RANGE_INVALID' => ['Indica un intervallo di placche valido (dal numero minore o uguale al numero finale).', 'Enter a valid plaque range (the first number not above the last).', 'Geben Sie einen gültigen Plattenbereich an (erste Nummer nicht grösser als die letzte).', 'Indiquez une plage de plaques valide (le premier numéro ne dépasse pas le dernier).'],
            'DAIRY.VAT.YIELD_PCT' => ['Resa casearia a 24 ore', '24 hour fresh yield', 'Frischausbeute nach 24 Stunden', 'Rendement frais à 24 heures'],
            'DAIRY.VAT.COMPLETE' => ['Completa e genera le forme', 'Complete and generate the wheels', 'Abschliessen und Laibe erzeugen', 'Terminer et générer les meules'],
            'DAIRY.VAT.COMPLETED' => ['Lotto completato: {count} forme generate.', 'Batch completed: {count} wheels generated.', 'Charge abgeschlossen: {count} Laibe erzeugt.', 'Lot terminé : {count} meules générées.'],
            'DAIRY.VAT.WHEELS_GENERATED' => ['{count} forme generate e assegnate.', '{count} wheels generated and assigned.', '{count} Laibe erzeugt und zugewiesen.', '{count} meules générées et attribuées.'],
            'DAIRY.VAT.COMPOSITION' => ['Composizione per allevatore (tracciabilità a monte)', 'Composition by breeder (upstream traceability)', 'Zusammensetzung nach Produzent (Rückverfolgbarkeit)', 'Composition par producteur (traçabilité amont)'],
            'DAIRY.VAT.TANK_USAGES' => ['Prelievi dalle cisterne', 'Draws from the tanks', 'Entnahmen aus den Tanks', 'Prélèvements dans les cuves'],
            'DAIRY.VAT.PROCESS_LOG' => ['Lavorazione', 'Make log', 'Verarbeitung', 'Fabrication'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function cellar(): array
    {
        return [
            'DAIRY.CELLAR.LEGEND' => ['Legenda età', 'Age legend', 'Altersleiste', 'Légende d’âge'],
            'DAIRY.CELLAR.LEGEND_OVERDUE' => ['Cura in ritardo', 'Overdue care', 'Überfällige Pflege', 'Soin en retard'],
            'DAIRY.CELLAR.LEGEND_RECALLED' => ['Richiamata', 'Recalled', 'Zurückgerufen', 'Rappelée'],
            'DAIRY.CELLAR.LEGEND_EMPTY' => ['Vuoto o venduto', 'Empty or sold', 'Leer oder verkauft', 'Vide ou vendue'],
            'DAIRY.CELLAR.AGE_YOUNG' => ['Meno di 6 mesi', 'Under 6 months', 'Unter 6 Monaten', 'Moins de 6 mois'],
            'DAIRY.CELLAR.AGE_MID' => ['6-12 mesi', '6-12 months', '6-12 Monate', '6-12 mois'],
            'DAIRY.CELLAR.AGE_MATURE' => ['12-24 mesi', '12-24 months', '12-24 Monate', '12-24 mois'],
            'DAIRY.CELLAR.AGE_OLD' => ['24-36 mesi', '24-36 months', '24-36 Monate', '24-36 mois'],
            'DAIRY.CELLAR.AGE_EXTRA' => ['Oltre 36 mesi', 'Over 36 months', 'Über 36 Monate', 'Plus de 36 mois'],
            'DAIRY.CELLAR.AGE_ALL' => ['Tutte le età', 'All ages', 'Alle Alter', 'Tous les âges'],
            'DAIRY.CELLAR.AGE_UNDER_12' => ['Meno di 12 mesi', 'Under 12 months', 'Unter 12 Monaten', 'Moins de 12 mois'],
            'DAIRY.CELLAR.AGE_12' => ['12 mesi', '12 months', '12 Monate', '12 mois'],
            'DAIRY.CELLAR.AGE_24' => ['24 mesi', '24 months', '24 Monate', '24 mois'],
            'DAIRY.CELLAR.AGE_36' => ['36 mesi e oltre', '36 months and more', '36 Monate und mehr', '36 mois et plus'],
            'DAIRY.CELLAR.OVERDUE_ALERT' => ['{count} cure di stagionatura in ritardo.', '{count} care operations overdue.', '{count} Pflegearbeiten überfällig.', '{count} soins d’affinage en retard.'],
            'DAIRY.CELLAR.OVERDUE_COUNT' => ['{count} in ritardo', '{count} overdue', '{count} überfällig', '{count} en retard'],
            'DAIRY.CELLAR.CARE_SHELF' => ['Cura sull’asse', 'Care for the shelf', 'Pflege des Bretts', 'Soin de la planche'],
            'DAIRY.CELLAR.CARE_RACK' => ['Cura sulla scalera', 'Care for the rack', 'Pflege des Gestells', 'Soin de l’étagère'],
            'DAIRY.CELLAR.NO_RACKS' => ['Nessuna scalera registrata.', 'No rack registered.', 'Kein Gestell erfasst.', 'Aucune étagère enregistrée.'],
            'DAIRY.CELLAR.NEW_RACK' => ['Nuova scalera / asse', 'New rack / shelf', 'Neues Gestell / Brett', 'Nouvelle étagère / planche'],
            'DAIRY.CELLAR.TOTAL_WHEELS' => ['Forme', 'Wheels', 'Laibe', 'Meules'],
            'DAIRY.CELLAR.TOTAL_INITIAL' => ['Peso iniziale', 'Initial weight', 'Anfangsgewicht', 'Poids initial'],
            'DAIRY.CELLAR.TOTAL_CURRENT' => ['Peso attuale', 'Current weight', 'Aktuelles Gewicht', 'Poids actuel'],
            'DAIRY.CELLAR.TOTAL_THEORETICAL' => ['Peso teorico', 'Theoretical weight', 'Theoretisches Gewicht', 'Poids théorique'],
            'DAIRY.CELLAR.TOTAL_LOSS' => ['Calo peso', 'Weight loss', 'Gewichtsverlust', 'Perte de poids'],
            'DAIRY.CELLAR.ONLY_OVERDUE' => ['Solo in ritardo', 'Overdue only', 'Nur überfällige', 'En retard seulement'],
            'DAIRY.CELLAR.SELL_SELECTED' => ['Vendi {count} forme', 'Sell {count} wheels', '{count} Laibe verkaufen', 'Vendre {count} meules'],
            'DAIRY.CELLAR.SALE_TITLE' => ['Vendita con pesatura', 'Sale with weighing', 'Verkauf mit Wägung', 'Vente avec pesée'],
            'DAIRY.CELLAR.SALE_TOTAL' => ['Peso venduto', 'Weight sold', 'Verkauftes Gewicht', 'Poids vendu'],
            'DAIRY.CELLAR.SALE_DONE' => ['{count} forme vendute', '{count} wheels sold', '{count} Laibe verkauft', '{count} meules vendues'],
            'DAIRY.CELLAR.SALE_SUMMARY' => ['Peso venduto {kg} kg, differenza dal peso a libro {variance} kg.', 'Weight sold {kg} kg, variance from the book weight {variance} kg.', 'Verkauftes Gewicht {kg} kg, Differenz zum Buchgewicht {variance} kg.', 'Poids vendu {kg} kg, écart avec le poids comptable {variance} kg.'],
            'DAIRY.CELLAR.WEIGH' => ['Pesa', 'Weigh', 'Wägen', 'Peser'],
            'DAIRY.CELLAR.GRADE' => ['Classifica', 'Grade', 'Klassieren', 'Classer'],
            'DAIRY.CELLAR.GRADING_TITLE' => ['Espertizzazione e marchiatura a fuoco', 'Expert grading and fire branding', 'Expertise und Brandzeichen', 'Expertise et marquage au feu'],
            'DAIRY.CELLAR.FIRE_BRANDING' => ['Marchiatura a fuoco del consorzio', 'Consortium fire branding', 'Brandzeichen des Konsortiums', 'Marquage au feu du consortium'],
            'DAIRY.CELLAR.BRANDED' => ['Marchiata', 'Branded', 'Gebrandet', 'Marquée'],
            'DAIRY.CELLAR.MOVE' => ['Sposta', 'Move', 'Verschieben', 'Déplacer'],
            'DAIRY.CARE.SCHEDULE' => ['Programma una cura', 'Schedule a care operation', 'Pflegearbeit planen', 'Planifier un soin'],
            'DAIRY.CARE.SCHEDULED' => ['Cura programmata.', 'Care operation scheduled.', 'Pflegearbeit geplant.', 'Soin planifié.'],
            'DAIRY.CARE.EXECUTE' => ['Esegui', 'Execute', 'Ausführen', 'Exécuter'],
            'DAIRY.CARE.EXECUTED' => ['Cura registrata.', 'Care operation recorded.', 'Pflegearbeit erfasst.', 'Soin enregistré.'],
            'DAIRY.CARE.BATCH_TITLE' => ['Cura su asse, scalera o lotto', 'Care for a shelf, rack or batch', 'Pflege für Brett, Gestell oder Charge', 'Soin pour une planche, une étagère ou un lot'],
            'DAIRY.CURVES.TITLE' => ['Curve di calo peso fisiologico', 'Physiological weight loss curves', 'Physiologische Gewichtsverlustkurven', 'Courbes de perte de poids physiologique'],
            'DAIRY.CURVES.NEW' => ['Nuova curva', 'New curve', 'Neue Kurve', 'Nouvelle courbe'],
            'DAIRY.CURVES.EDITOR' => ['Curva di calo peso', 'Weight loss curve', 'Gewichtsverlustkurve', 'Courbe de perte de poids'],
            'DAIRY.CURVES.ADD_POINT' => ['Aggiungi punto', 'Add point', 'Punkt hinzufügen', 'Ajouter un point'],
            'DAIRY.CURVES.MONTHS_SHORT' => ['m', 'm', 'M', 'm'],
            'DAIRY.CURVES.SHAPE' => ['Andamento', 'Shape', 'Verlauf', 'Allure'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function compliance(): array
    {
        return [
            'DAIRY.FILING.INTRO_DBMILCH' => ['Dichiarazione mensile delle consegne dei produttori e dell’utilizzo del latte per DBMilch.ch / TSM, con il supplemento per il latte trasformato in formaggio.', 'Monthly producer delivery and milk utilization declaration for DBMilch.ch / TSM, with the cheese-milk supplement.', 'Monatliche Meldung der Produzentenlieferungen und der Milchverwertung für DBMilch.ch / TSM, mit der Verkäsungszulage.', 'Déclaration mensuelle des livraisons des producteurs et de l’utilisation du lait pour DBMilch.ch / TSM, avec le supplément de transformation fromagère.'],
            'DAIRY.FILING.INTRO_SIAN' => ['Movimenti del Registro telematico del latte SIAN (D.M. 27/05/2021): carico del latte, trasformazione in formaggio e siero, giacenze in stagionatura e vendite.', 'Movements of the SIAN telematic milk register (D.M. 27/05/2021): milk loading, transformation into cheese and whey, aging stock and sales.', 'Bewegungen des telematischen Milchregisters SIAN (D.M. 27.05.2021): Milcheingang, Verarbeitung zu Käse und Molke, Reifebestand und Verkäufe.', 'Mouvements du registre télématique du lait SIAN (D.M. 27/05/2021) : chargement du lait, transformation en fromage et petit-lait, stock en affinage et ventes.'],
            'DAIRY.FILING.VALIDATE' => ['Convalida e anteprima', 'Validate and preview', 'Prüfen und Vorschau', 'Valider et prévisualiser'],
            'DAIRY.FILING.PREVIEW_READY' => ['Anteprima dei movimenti pronta.', 'Movement preview ready.', 'Vorschau der Bewegungen bereit.', 'Aperçu des mouvements prêt.'],
            'DAIRY.FILING.VALID' => ['Il periodo è valido e può essere generato.', 'The period is valid and can be generated.', 'Die Periode ist gültig und kann erzeugt werden.', 'La période est valide et peut être générée.'],
            'DAIRY.FILING.INVALID' => ['Il periodo non è valido', 'The period is not valid', 'Die Periode ist ungültig', 'La période n’est pas valide'],
            'DAIRY.FILING.WARNINGS' => ['Avvisi', 'Warnings', 'Hinweise', 'Avertissements'],
            'DAIRY.FILING.GENERATE' => ['Genera il file', 'Generate the file', 'Datei erzeugen', 'Générer le fichier'],
            'DAIRY.FILING.GENERATED' => ['File generato.', 'File generated.', 'Datei erzeugt.', 'Fichier généré.'],
            'DAIRY.FILING.DOWNLOAD' => ['Scarica', 'Download', 'Herunterladen', 'Télécharger'],
            'DAIRY.FILING.MARK_SUBMITTED' => ['Segna come inviato', 'Mark as submitted', 'Als eingereicht markieren', 'Marquer comme transmis'],
            'DAIRY.FILING.SUBMITTED' => ['Dichiarazione segnata come inviata.', 'Filing marked as submitted.', 'Meldung als eingereicht markiert.', 'Déclaration marquée comme transmise.'],
            'DAIRY.FILING.HISTORY' => ['Dichiarazioni generate', 'Generated filings', 'Erzeugte Meldungen', 'Déclarations générées'],
            'DAIRY.FILING.READ_ONLY' => ['La generazione dei file e il ritiro dei lotti sono riservati a chi ha l’abilitazione agli adempimenti.', 'Generating the files and recalling lots are reserved to users with the regulatory ability.', 'Das Erzeugen der Dateien und der Rückruf von Chargen sind Benutzern mit der Berechtigung für Meldungen vorbehalten.', 'La génération des fichiers et le rappel des lots sont réservés aux utilisateurs habilités aux déclarations.'],
            'DAIRY.TRACE.SEARCH' => ['Traccia', 'Trace', 'Verfolgen', 'Tracer'],
            'DAIRY.TRACE.EMPTY' => ['Cerca una matricola, un lotto, un conferimento o un’analisi per vedere la filiera.', 'Search a serial, batch, delivery or analysis to see the lineage.', 'Suchen Sie eine Seriennummer, Charge, Anlieferung oder Analyse, um die Lieferkette zu sehen.', 'Recherchez un matricule, un lot, une livraison ou une analyse pour voir la filière.'],
            'DAIRY.TRACE.FARMERS' => ['Allevatori', 'Breeders', 'Produzenten', 'Producteurs'],
            'DAIRY.TRACE.DELIVERIES' => ['Conferimenti', 'Deliveries', 'Anlieferungen', 'Livraisons'],
            'DAIRY.TRACE.TANKS' => ['Cisterne', 'Tanks', 'Tanks', 'Cuves'],
            'DAIRY.TRACE.BATCHES' => ['Lotti di caldaia', 'Vat batches', 'Kessi-Chargen', 'Lots de cuve'],
            'DAIRY.TRACE.WHEELS' => ['Forme', 'Wheels', 'Laibe', 'Meules'],
            'DAIRY.TRACE.CUSTOMERS' => ['Clienti', 'Customers', 'Kunden', 'Clients'],
            'DAIRY.TRACE.WHEELS_KG' => ['Peso forme', 'Wheels weight', 'Laibgewicht', 'Poids des meules'],
            'DAIRY.TRACE.LINEAGE' => ['Filiera', 'Lineage', 'Lieferkette', 'Filière'],
            'DAIRY.TRACE.RECALL' => ['Rapporto di ritiro', 'Recall report', 'Rückrufbericht', 'Rapport de rappel'],
            'DAIRY.TRACE.APPLY_RECALL' => ['Segna le forme coinvolte come richiamate', 'Mark the affected wheels as recalled', 'Betroffene Laibe als zurückgerufen markieren', 'Marquer les meules concernées comme rappelées'],
            'DAIRY.TRACE.CONFIRM_RECALL' => ['Le forme coinvolte saranno bloccate come richiamate. Procedere?', 'The affected wheels will be blocked as recalled. Proceed?', 'Die betroffenen Laibe werden als zurückgerufen gesperrt. Fortfahren?', 'Les meules concernées seront bloquées comme rappelées. Continuer ?'],
            'DAIRY.TRACE.GENERATE_REPORT' => ['Genera rapporto di ritiro', 'Generate recall report', 'Rückrufbericht erstellen', 'Générer le rapport de rappel'],
            'DAIRY.TRACE.REPORT_DONE' => ['Rapporto di ritiro generato.', 'Recall report generated.', 'Rückrufbericht erstellt.', 'Rapport de rappel généré.'],
            'DAIRY.TRACE.REPORT_READY' => ['Rapporto {id}', 'Report {id}', 'Bericht {id}', 'Rapport {id}'],
            'DAIRY.TRACE.AFFECTED_CUSTOMERS' => ['Clienti coinvolti', 'Affected customers', 'Betroffene Kunden', 'Clients concernés'],
            'DAIRY.TRACE.AFFECTED_WHEELS' => ['Forme coinvolte', 'Affected wheels', 'Betroffene Laibe', 'Meules concernées'],
            'DAIRY.TRACE.PRINT_REPORT' => ['Stampa il rapporto', 'Print the report', 'Bericht drucken', 'Imprimer le rapport'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enums(): array
    {
        return [
            'DAIRY.ENUM.SESSION.MORNING' => ['Mattina', 'Morning', 'Morgen', 'Matin'],
            'DAIRY.ENUM.SESSION.EVENING' => ['Sera', 'Evening', 'Abend', 'Soir'],
            'DAIRY.ENUM.TANK_STATUS.ACTIVE' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'DAIRY.ENUM.TANK_STATUS.CLEANING' => ['In pulizia', 'Cleaning', 'In Reinigung', 'En nettoyage'],
            'DAIRY.ENUM.TANK_STATUS.OUT_OF_SERVICE' => ['Fuori servizio', 'Out of service', 'Ausser Betrieb', 'Hors service'],
            'DAIRY.ENUM.JURISDICTION.CH' => ['Svizzera', 'Switzerland', 'Schweiz', 'Suisse'],
            'DAIRY.ENUM.JURISDICTION.IT' => ['Italia', 'Italy', 'Italien', 'Italie'],
            'DAIRY.ENUM.DELIVERY_STATUS.REGISTERED' => ['Registrato', 'Registered', 'Erfasst', 'Enregistré'],
            'DAIRY.ENUM.DELIVERY_STATUS.QUARANTINED' => ['In quarantena', 'Quarantined', 'Gesperrt', 'En quarantaine'],
            'DAIRY.ENUM.DELIVERY_STATUS.ACCEPTED' => ['Accettato', 'Accepted', 'Akzeptiert', 'Accepté'],
            'DAIRY.ENUM.DELIVERY_STATUS.REJECTED' => ['Respinto', 'Rejected', 'Abgelehnt', 'Refusé'],
            'DAIRY.ENUM.INHIBITOR_RESULT.NEGATIVE' => ['Negativo', 'Negative', 'Negativ', 'Négatif'],
            'DAIRY.ENUM.INHIBITOR_RESULT.POSITIVE' => ['Positivo', 'Positive', 'Positiv', 'Positif'],
            'DAIRY.ENUM.INHIBITOR_RESULT.NOT_TESTED' => ['Non testato', 'Not tested', 'Nicht geprüft', 'Non testé'],
            'DAIRY.ENUM.IMPORT_FORMAT.SUISSELAB' => ['Suisselab', 'Suisselab', 'Suisselab', 'Suisselab'],
            'DAIRY.ENUM.IMPORT_FORMAT.TSM' => ['TSM Treuhand', 'TSM Treuhand', 'TSM Treuhand', 'TSM Fiduciaire'],
            'DAIRY.ENUM.IMPORT_FORMAT.AIA' => ['AIA', 'AIA', 'AIA', 'AIA'],
            'DAIRY.ENUM.IMPORT_FORMAT.ARAL' => ['ARAL', 'ARAL', 'ARAL', 'ARAL'],
            'DAIRY.ENUM.IMPORT_FORMAT.GENERIC_CSV' => ['CSV generico', 'Generic CSV', 'Generisches CSV', 'CSV générique'],
            'DAIRY.ENUM.PRICING_COMPONENT_TYPE.FAT' => ['Grasso', 'Fat', 'Fett', 'Matière grasse'],
            'DAIRY.ENUM.PRICING_COMPONENT_TYPE.PROTEIN' => ['Proteine', 'Protein', 'Eiweiss', 'Protéines'],
            'DAIRY.ENUM.PRICING_COMPONENT_TYPE.TBC' => ['Carica batterica', 'Bacterial count', 'Keimzahl', 'Charge bactérienne'],
            'DAIRY.ENUM.PRICING_COMPONENT_TYPE.SCC' => ['Cellule somatiche', 'Somatic cells', 'Zellzahl', 'Cellules somatiques'],
            'DAIRY.ENUM.PRICING_COMPONENT_TYPE.INHIBITOR' => ['Inibitori', 'Inhibitors', 'Hemmstoffe', 'Inhibiteurs'],
            'DAIRY.ENUM.PRICING_COMPONENT_TYPE.FREEZING_POINT' => ['Punto crioscopico', 'Freezing point', 'Gefrierpunkt', 'Point cryoscopique'],
            'DAIRY.ENUM.PRICING_COMPONENT_TYPE.CASEIN' => ['Caseina', 'Casein', 'Kasein', 'Caséine'],
            'DAIRY.ENUM.PRICING_COMPONENT_TYPE.UREA' => ['Urea', 'Urea', 'Harnstoff', 'Urée'],
            'DAIRY.ENUM.PRICING_COMPONENT_TYPE.CHEESE_MILK_SUPPLEMENT' => ['Supplemento latte trasformato in formaggio', 'Cheese-milk supplement', 'Verkäsungszulage', 'Supplément de transformation fromagère'],
            'DAIRY.ENUM.PRICING_COMPONENT_TYPE.DOP_PREMIUM' => ['Premio DOP / AOP', 'DOP / AOP premium', 'DOP- / AOP-Zuschlag', 'Prime AOP / DOP'],
            'DAIRY.ENUM.SETTLEMENT_STATUS.DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'DAIRY.ENUM.SETTLEMENT_STATUS.APPROVED' => ['Approvata', 'Approved', 'Genehmigt', 'Approuvé'],
            'DAIRY.ENUM.SETTLEMENT_STATUS.INVOICED' => ['Fatturata', 'Invoiced', 'Verrechnet', 'Facturé'],
            'DAIRY.ENUM.SETTLEMENT_STATUS.PAID' => ['Pagata', 'Paid', 'Bezahlt', 'Payé'],
            'DAIRY.ENUM.DOCUMENT_TYPE.SELF_BILLING' => ['Autofattura', 'Self-billing', 'Gutschrift (Selbstfakturierung)', 'Autofacturation'],
            'DAIRY.ENUM.DOCUMENT_TYPE.CREDIT_NOTE' => ['Nota di credito', 'Credit note', 'Gutschrift', 'Note de crédit'],
            'DAIRY.ENUM.DOCUMENT_TYPE.INVOICE' => ['Fattura', 'Invoice', 'Rechnung', 'Facture'],
            'DAIRY.ENUM.VAT_BATCH_STATUS.OPEN' => ['Aperto', 'Open', 'Offen', 'Ouvert'],
            'DAIRY.ENUM.VAT_BATCH_STATUS.IN_PRODUCTION' => ['In lavorazione', 'In production', 'In Produktion', 'En fabrication'],
            'DAIRY.ENUM.VAT_BATCH_STATUS.COMPLETED' => ['Completato', 'Completed', 'Abgeschlossen', 'Terminé'],
            'DAIRY.ENUM.VAT_BATCH_STATUS.AGING' => ['In stagionatura', 'Aging', 'In Reifung', 'En affinage'],
            'DAIRY.ENUM.VAT_BATCH_STATUS.CLOSED' => ['Chiuso', 'Closed', 'Geschlossen', 'Clôturé'],
            'DAIRY.ENUM.CHEESE_TYPE.PARMIGIANO_REGGIANO_DOP' => ['Parmigiano Reggiano DOP', 'Parmigiano Reggiano DOP', 'Parmigiano Reggiano DOP', 'Parmigiano Reggiano AOP'],
            'DAIRY.ENUM.CHEESE_TYPE.GRANA_PADANO_DOP' => ['Grana Padano DOP', 'Grana Padano DOP', 'Grana Padano DOP', 'Grana Padano AOP'],
            'DAIRY.ENUM.CHEESE_TYPE.GRUYERE_AOP' => ['Gruyère AOP', 'Gruyère AOP', 'Gruyère AOP', 'Gruyère AOP'],
            'DAIRY.ENUM.CHEESE_TYPE.SBRINZ_AOP' => ['Sbrinz AOP', 'Sbrinz AOP', 'Sbrinz AOP', 'Sbrinz AOP'],
            'DAIRY.ENUM.CHEESE_TYPE.ALPE_TICINESE_DOP' => ['Formaggio d’Alpe Ticinese DOP', 'Formaggio d’Alpe Ticinese DOP', 'Formaggio d’Alpe Ticinese DOP', 'Formaggio d’Alpe Ticinese AOP'],
            'DAIRY.ENUM.CHEESE_TYPE.GENERIC' => ['Altro formaggio', 'Other cheese', 'Anderer Käse', 'Autre fromage'],
            'DAIRY.ENUM.CARE_TYPE.BRINING' => ['Salamoia', 'Brining', 'Salzbad', 'Saumurage'],
            'DAIRY.ENUM.CARE_TYPE.TURNING' => ['Rivoltatura', 'Turning', 'Wenden', 'Retournement'],
            'DAIRY.ENUM.CARE_TYPE.BRUSHING' => ['Spazzolatura', 'Brushing', 'Bürsten', 'Brossage'],
            'DAIRY.ENUM.CARE_TYPE.RIND_WASHING' => ['Lavaggio della crosta', 'Rind washing', 'Rindenwäsche', 'Lavage de la croûte'],
            'DAIRY.ENUM.CARE_TYPE.PIERCING' => ['Foratura', 'Piercing', 'Anstechen', 'Perçage'],
            'DAIRY.ENUM.CARE_TYPE.EXPERT_GRADING' => ['Espertizzazione (battitura)', 'Expert grading', 'Expertise (Klopfprobe)', 'Expertise (sondage)'],
            'DAIRY.ENUM.CARE_STATUS.SCHEDULED' => ['Programmata', 'Scheduled', 'Geplant', 'Planifié'],
            'DAIRY.ENUM.CARE_STATUS.DONE' => ['Eseguita', 'Done', 'Ausgeführt', 'Exécuté'],
            'DAIRY.ENUM.CARE_STATUS.SKIPPED' => ['Saltata', 'Skipped', 'Übersprungen', 'Ignoré'],
            'DAIRY.ENUM.CARE_SCOPE.WHEEL' => ['Forma', 'Wheel', 'Laib', 'Meule'],
            'DAIRY.ENUM.CARE_SCOPE.SHELF' => ['Asse', 'Shelf', 'Brett', 'Planche'],
            'DAIRY.ENUM.CARE_SCOPE.RACK' => ['Scalera', 'Rack', 'Gestell', 'Étagère'],
            'DAIRY.ENUM.CARE_SCOPE.BATCH' => ['Lotto', 'Batch', 'Charge', 'Lot'],
            'DAIRY.ENUM.WHEEL_STATUS.BRINE' => ['In salamoia', 'In brine', 'In der Lake', 'En saumure'],
            'DAIRY.ENUM.WHEEL_STATUS.AGING' => ['In stagionatura', 'Aging', 'In Reifung', 'En affinage'],
            'DAIRY.ENUM.WHEEL_STATUS.SOLD' => ['Venduta', 'Sold', 'Verkauft', 'Vendue'],
            'DAIRY.ENUM.WHEEL_STATUS.RECALLED' => ['Richiamata', 'Recalled', 'Zurückgerufen', 'Rappelée'],
            'DAIRY.ENUM.WHEEL_GRADE.UNGRADED' => ['Non classificata', 'Ungraded', 'Nicht klassiert', 'Non classée'],
            'DAIRY.ENUM.WHEEL_GRADE.FIRST_CATEGORY' => ['1ª categoria', '1st category', '1. Kategorie', '1re catégorie'],
            'DAIRY.ENUM.WHEEL_GRADE.SECOND_CATEGORY' => ['2ª categoria', '2nd category', '2. Kategorie', '2e catégorie'],
            'DAIRY.ENUM.WHEEL_GRADE.DISCARD' => ['Scarto', 'Discard', 'Ausschuss', 'Rebut'],
            'DAIRY.ENUM.AUTHORITY.DBMILCH' => ['DBMilch.ch / TSM', 'DBMilch.ch / TSM', 'DBMilch.ch / TSM', 'DBMilch.ch / TSM'],
            'DAIRY.ENUM.AUTHORITY.SIAN' => ['SIAN', 'SIAN', 'SIAN', 'SIAN'],
            'DAIRY.ENUM.SUBMISSION_STATUS.GENERATED' => ['Generata', 'Generated', 'Erzeugt', 'Générée'],
            'DAIRY.ENUM.SUBMISSION_STATUS.SUBMITTED' => ['Inviata', 'Submitted', 'Eingereicht', 'Transmise'],
            'DAIRY.ENUM.SUBMISSION_STATUS.FAILED' => ['Non riuscita', 'Failed', 'Fehlgeschlagen', 'Échouée'],
            'DAIRY.ENUM.DEADLINE_STATUS.PENDING' => ['Da generare', 'To generate', 'Zu erzeugen', 'À générer'],
            'DAIRY.ENUM.DEADLINE_STATUS.GENERATED' => ['Generata', 'Generated', 'Erzeugt', 'Générée'],
            'DAIRY.ENUM.DEADLINE_STATUS.SUBMITTED' => ['Inviata', 'Submitted', 'Eingereicht', 'Transmise'],
            'DAIRY.ENUM.DEADLINE_STATUS.OVERDUE' => ['Scaduta', 'Overdue', 'Überfällig', 'En retard'],
            'DAIRY.ENUM.TRACE_SOURCE.DELIVERY' => ['Conferimento', 'Delivery', 'Anlieferung', 'Livraison'],
            'DAIRY.ENUM.TRACE_SOURCE.TEST' => ['Analisi di laboratorio', 'Laboratory analysis', 'Laboranalyse', 'Analyse de laboratoire'],
            'DAIRY.ENUM.TRACE_SOURCE.BATCH' => ['Lotto di caldaia', 'Vat batch', 'Kessi-Charge', 'Lot de cuve'],
            'DAIRY.ENUM.TRACE_SOURCE.WHEEL' => ['Forma (matricola)', 'Wheel (serial)', 'Laib (Seriennummer)', 'Meule (matricule)'],
            'DAIRY.ENUM.TRACE_DIRECTION.FORWARD' => ['A valle: dove è finito', 'Forward: where it went', 'Vorwärts: wohin es ging', 'Aval : où cela est allé'],
            'DAIRY.ENUM.TRACE_DIRECTION.BACKWARD' => ['A monte: da dove viene', 'Backward: where it came from', 'Rückwärts: woher es kam', 'Amont : d’où cela vient'],
            'DAIRY.ENUM.TRACE_NODE.FARMER' => ['Allevatore', 'Breeder', 'Produzent', 'Producteur'],
            'DAIRY.ENUM.TRACE_NODE.DELIVERY' => ['Conferimento', 'Delivery', 'Anlieferung', 'Livraison'],
            'DAIRY.ENUM.TRACE_NODE.TEST' => ['Analisi', 'Analysis', 'Analyse', 'Analyse'],
            'DAIRY.ENUM.TRACE_NODE.TANK' => ['Cisterna', 'Tank', 'Tank', 'Cuve'],
            'DAIRY.ENUM.TRACE_NODE.BATCH' => ['Lotto', 'Batch', 'Charge', 'Lot'],
            'DAIRY.ENUM.TRACE_NODE.WHEEL' => ['Forma', 'Wheel', 'Laib', 'Meule'],
            'DAIRY.ENUM.TRACE_NODE.CUSTOMER' => ['Cliente', 'Customer', 'Kunde', 'Client'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function codes(): array
    {
        return [
            'DAIRY.FLAG.INHIBITOR_POSITIVE' => ['Inibitori positivi', 'Positive inhibitors', 'Positive Hemmstoffe', 'Inhibiteurs positifs'],
            'DAIRY.FLAG.SCC_HIGH' => ['Cellule somatiche elevate', 'High somatic cells', 'Hohe Zellzahl', 'Cellules somatiques élevées'],
            'DAIRY.FLAG.TBC_HIGH' => ['Carica batterica elevata', 'High bacterial count', 'Hohe Keimzahl', 'Charge bactérienne élevée'],
            'DAIRY.FLAG.FREEZING_POINT_ANOMALY' => ['Punto crioscopico anomalo (acqua aggiunta?)', 'Abnormal freezing point (added water?)', 'Auffälliger Gefrierpunkt (Wasserzusatz?)', 'Point cryoscopique anormal (eau ajoutée ?)'],
            'DAIRY.ERROR.GENERIC' => ['Operazione non riuscita.', 'The operation failed.', 'Der Vorgang ist fehlgeschlagen.', 'L’opération a échoué.'],
            'DAIRY.ERROR.VALIDATION' => ['I dati inseriti non sono validi.', 'The entered data is not valid.', 'Die eingegebenen Daten sind ungültig.', 'Les données saisies ne sont pas valides.'],
            'DAIRY.ERROR.FORBIDDEN' => ['Non hai i permessi per questa operazione.', 'You are not allowed to do this.', 'Sie haben keine Berechtigung für diesen Vorgang.', 'Vous n’avez pas les droits pour cette opération.'],
            'DAIRY.ERROR.NOT_FOUND' => ['Elemento non trovato.', 'Item not found.', 'Element nicht gefunden.', 'Élément introuvable.'],
            'DAIRY.ERROR.TANK_INSUFFICIENT_VOLUME' => ['La cisterna non contiene abbastanza latte.', 'The tank does not hold enough milk.', 'Der Tank enthält nicht genug Milch.', 'La cuve ne contient pas assez de lait.'],
            'DAIRY.ERROR.DELIVERY_SETTLED' => ['Il conferimento è già in una liquidazione e non può essere modificato.', 'The delivery is already in a settlement and cannot be changed.', 'Die Anlieferung ist bereits abgerechnet und kann nicht geändert werden.', 'La livraison est déjà dans un décompte et ne peut pas être modifiée.'],
            'DAIRY.ERROR.PERIOD_INVALID' => ['Il periodo non è valido per questa dichiarazione.', 'The period is not valid for this filing.', 'Die Periode ist für diese Meldung ungültig.', 'La période n’est pas valide pour cette déclaration.'],
            'DAIRY.ERROR.SETTLEMENT_EXISTS' => ['Esiste già una liquidazione approvata per questo periodo.', 'An approved settlement already exists for this period.', 'Für diese Periode existiert bereits eine genehmigte Abrechnung.', 'Un décompte approuvé existe déjà pour cette période.'],
            'DAIRY.ERROR.BATCH_NOT_OPEN' => ['Il lotto non è più modificabile.', 'The batch can no longer be changed.', 'Die Charge kann nicht mehr geändert werden.', 'Le lot ne peut plus être modifié.'],
            'DAIRY.ERROR.PLAQUE_RANGE_INVALID' => ['L’intervallo di placche non è valido o si sovrappone a un altro lotto.', 'The plaque range is not valid or overlaps another batch.', 'Der Plattenbereich ist ungültig oder überschneidet sich mit einer anderen Charge.', 'La plage de plaques n’est pas valide ou chevauche un autre lot.'],
            'DAIRY.ERROR.WHEEL_NOT_SELLABLE' => ['Una o più forme non sono vendibili.', 'One or more wheels cannot be sold.', 'Ein oder mehrere Laibe sind nicht verkäuflich.', 'Une ou plusieurs meules ne peuvent pas être vendues.'],
            'DAIRY.ERROR.IMPORT_INVALID' => ['Il file di laboratorio non è leggibile nel formato scelto.', 'The laboratory file cannot be read in the chosen format.', 'Die Labordatei ist im gewählten Format nicht lesbar.', 'Le fichier de laboratoire n’est pas lisible dans le format choisi.'],
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
