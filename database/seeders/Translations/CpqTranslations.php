<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * Texts of the technical and commercial quoting (CPQ lite) in it, en, de, fr: the labels of the quote builder, the
 * configurator, the margin simulator, the revision comparison, the approval queue and the online signature page, and
 * the clauses, labels and disclaimers of the quote PDF (`PDF.QUOTE.*`, read by the PDF service in the language of the
 * document). Seeded by CpqLocalizationSeeder.
 */
final class CpqTranslations
{
    private const array Locales = ['it' => 0, 'en' => 1, 'de' => 2, 'fr' => 3];

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = self::Locales[$locale] ?? throw new InvalidArgumentException("Unsupported locale [{$locale}].");

        return array_map(static fn (array $texts): string => $texts[$index], self::definitions());
    }

    /**
     * Key => [it, en, de, fr].
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            // Quote builder and configurator
            'CPQ.BUILDER.TITLE' => ['Preventivo tecnico e commerciale', 'Technical and commercial quote', 'Technisches und kommerzielles Angebot', 'Devis technique et commercial'],
            'CPQ.BUILDER.ADD_LINE' => ['Aggiungi riga', 'Add line', 'Position hinzufügen', 'Ajouter une ligne'],
            'CPQ.BUILDER.CONFIGURE' => ['Configura', 'Configure', 'Konfigurieren', 'Configurer'],
            'CPQ.BUILDER.OPTIONAL_LINE' => ['Riga opzionale', 'Optional line', 'Optionale Position', 'Ligne optionnelle'],
            'CPQ.BUILDER.ALLOW_OPTIONAL_SELECTION' => ['Il cliente può scegliere le righe opzionali', 'Customer may choose the optional lines', 'Kunde darf optionale Positionen wählen', 'Le client peut choisir les lignes optionnelles'],
            'CPQ.BUILDER.SAVE' => ['Salva preventivo', 'Save quote', 'Angebot speichern', 'Enregistrer le devis'],
            'CPQ.CONFIGURATOR.TITLE' => ['Configuratore di variante', 'Variant configurator', 'Variantenkonfigurator', 'Configurateur de variante'],
            'CPQ.CONFIGURATOR.ATTRIBUTES' => ['Attributi', 'Attributes', 'Attribute', 'Attributs'],
            'CPQ.CONFIGURATOR.COLOR' => ['Colore', 'Color', 'Farbe', 'Couleur'],
            'CPQ.CONFIGURATOR.FINISH' => ['Finitura', 'Finish', 'Oberfläche', 'Finition'],
            'CPQ.CONFIGURATOR.DIMENSIONS' => ['Dimensioni e specifiche', 'Dimensions and specs', 'Abmessungen und Spezifikationen', 'Dimensions et spécifications'],
            'CPQ.CONFIGURATOR.ACCESSORIES' => ['Accessori', 'Accessories', 'Zubehör', 'Accessoires'],
            'CPQ.CONFIGURATOR.MANDATORY' => ['Obbligatorio', 'Mandatory', 'Pflicht', 'Obligatoire'],
            'CPQ.CONFIGURATOR.OPTIONAL' => ['Opzionale', 'Optional', 'Optional', 'Optionnel'],
            'CPQ.CONFIGURATOR.REQUIRES' => ['Richiede', 'Requires', 'Erfordert', 'Nécessite'],
            'CPQ.CONFIGURATOR.EXCLUDES' => ['Esclude', 'Excludes', 'Schliesst aus', 'Exclut'],
            'CPQ.CONFIGURATOR.PRICE_DELTA' => ['Differenza di prezzo', 'Price delta', 'Preisdifferenz', 'Écart de prix'],
            'CPQ.CONFIGURATOR.APPLY' => ['Applica configurazione', 'Apply configuration', 'Konfiguration übernehmen', 'Appliquer la configuration'],
            // Cost and margin
            'CPQ.MARGIN.TITLE' => ['Costi e margine', 'Cost and margin', 'Kosten und Marge', 'Coûts et marge'],
            'CPQ.MARGIN.INDUSTRIAL_COST' => ['Costo industriale stimato', 'Estimated industrial cost', 'Geschätzte Herstellkosten', 'Coût industriel estimé'],
            'CPQ.MARGIN.LIST_PRICE' => ['Prezzo di listino', 'List price', 'Listenpreis', 'Prix catalogue'],
            'CPQ.MARGIN.NET_PRICE' => ['Prezzo netto di vendita', 'Net selling price', 'Netto-Verkaufspreis', 'Prix de vente net'],
            'CPQ.MARGIN.MARGIN_AMOUNT' => ['Margine operativo lordo', 'Gross operating margin', 'Operative Bruttomarge', 'Marge opérationnelle brute'],
            'CPQ.MARGIN.MARGIN_PERCENT' => ['Margine %', 'Margin %', 'Marge %', 'Marge %'],
            'CPQ.MARGIN.HIDDEN' => ['Costi e margine non sono visibili per il tuo ruolo', 'Cost and margin are not visible for your role', 'Kosten und Marge sind für Ihre Rolle nicht sichtbar', 'Les coûts et la marge ne sont pas visibles pour votre rôle'],
            // Simulator
            'CPQ.SIMULATOR.TITLE' => ['Simulatore sconto e margine', 'Discount and margin simulator', 'Rabatt- und Margensimulator', 'Simulateur de remise et de marge'],
            'CPQ.SIMULATOR.MODE' => ['Obiettivo', 'Goal', 'Ziel', 'Objectif'],
            'CPQ.SIMULATOR.MODE_DISCOUNT' => ['Sconto %', 'Discount %', 'Rabatt %', 'Remise %'],
            'CPQ.SIMULATOR.MODE_TARGET_MARGIN' => ['Margine obiettivo %', 'Target margin %', 'Zielmarge %', 'Marge cible %'],
            'CPQ.SIMULATOR.MODE_TARGET_PRICE' => ['Prezzo totale obiettivo', 'Target total price', 'Zielgesamtpreis', 'Prix total cible'],
            'CPQ.SIMULATOR.VALUE' => ['Valore', 'Value', 'Wert', 'Valeur'],
            'CPQ.SIMULATOR.RUN' => ['Simula', 'Simulate', 'Simulieren', 'Simuler'],
            'CPQ.SIMULATOR.CURRENT' => ['Attuale', 'Current', 'Aktuell', 'Actuel'],
            'CPQ.SIMULATOR.SIMULATED' => ['Simulato', 'Simulated', 'Simuliert', 'Simulé'],
            'CPQ.SIMULATOR.DISCOUNT_NEEDED' => ['Sconto globale necessario', 'Global discount needed', 'Benötigter Gesamtrabatt', 'Remise globale nécessaire'],
            'CPQ.SIMULATOR.NOT_ACHIEVABLE' => ['L\'obiettivo non è raggiungibile', 'The goal cannot be reached', 'Das Ziel ist nicht erreichbar', 'L\'objectif ne peut pas être atteint'],
            // Revisions
            'CPQ.REVISIONS.TITLE' => ['Revisioni', 'Revisions', 'Revisionen', 'Révisions'],
            'CPQ.REVISIONS.NEW' => ['Nuova revisione', 'New revision', 'Neue Revision', 'Nouvelle révision'],
            'CPQ.REVISIONS.NOTES' => ['Note di revisione', 'Revision notes', 'Revisionsnotizen', 'Notes de révision'],
            'CPQ.REVISIONS.CURRENT' => ['Corrente', 'Current', 'Aktuell', 'Courante'],
            'CPQ.REVISIONS.SUPERSEDED' => ['Sostituita', 'Superseded', 'Ersetzt', 'Remplacée'],
            'CPQ.REVISIONS.COMPARE' => ['Confronta revisioni', 'Compare revisions', 'Revisionen vergleichen', 'Comparer les révisions'],
            'CPQ.REVISIONS.FROM' => ['Da', 'From', 'Von', 'De'],
            'CPQ.REVISIONS.TO' => ['A', 'To', 'Nach', 'À'],
            'CPQ.REVISIONS.ADDED' => ['Righe aggiunte', 'Added lines', 'Hinzugefügte Positionen', 'Lignes ajoutées'],
            'CPQ.REVISIONS.REMOVED' => ['Righe rimosse', 'Removed lines', 'Entfernte Positionen', 'Lignes supprimées'],
            'CPQ.REVISIONS.MODIFIED' => ['Righe modificate', 'Modified lines', 'Geänderte Positionen', 'Lignes modifiées'],
            'CPQ.REVISIONS.NO_CHANGES' => ['Nessuna differenza', 'No differences', 'Keine Unterschiede', 'Aucune différence'],
            'CPQ.REVISIONS.TOTALS_IMPACT' => ['Impatto su totali e margine', 'Impact on totals and margin', 'Auswirkung auf Summen und Marge', 'Impact sur les totaux et la marge'],
            // Approval
            'CPQ.APPROVAL.TITLE' => ['Approvazione sconti', 'Discount approval', 'Rabattfreigabe', 'Approbation des remises'],
            'CPQ.APPROVAL.BANNER_PENDING' => ['Il preventivo attende l\'approvazione: invio e accettazione sono bloccati', 'The quote awaits approval: sending and acceptance are blocked', 'Das Angebot wartet auf Freigabe: Versand und Annahme sind gesperrt', 'Le devis attend une approbation : l\'envoi et l\'acceptation sont bloqués'],
            'CPQ.APPROVAL.BANNER_REJECTED' => ['Il preventivo è stato rifiutato', 'The quote was rejected', 'Das Angebot wurde abgelehnt', 'Le devis a été refusé'],
            'CPQ.APPROVAL.SUBMIT' => ['Invia in approvazione', 'Submit for approval', 'Zur Freigabe einreichen', 'Soumettre à approbation'],
            'CPQ.APPROVAL.APPROVE' => ['Approva', 'Approve', 'Freigeben', 'Approuver'],
            'CPQ.APPROVAL.REJECT' => ['Rifiuta', 'Reject', 'Ablehnen', 'Refuser'],
            'CPQ.APPROVAL.REASON' => ['Motivo del rifiuto', 'Rejection reason', 'Ablehnungsgrund', 'Motif du refus'],
            'CPQ.APPROVAL.LEVEL' => ['Livello', 'Level', 'Stufe', 'Niveau'],
            'CPQ.APPROVAL.ROLE' => ['Ruolo approvatore', 'Approver role', 'Freigebende Rolle', 'Rôle approbateur'],
            'CPQ.APPROVAL.STATUS_PENDING' => ['In attesa', 'Pending', 'Ausstehend', 'En attente'],
            'CPQ.APPROVAL.STATUS_APPROVED' => ['Approvato', 'Approved', 'Freigegeben', 'Approuvé'],
            'CPQ.APPROVAL.STATUS_REJECTED' => ['Rifiutato', 'Rejected', 'Abgelehnt', 'Refusé'],
            'CPQ.APPROVAL.DECIDED_AT' => ['Deciso il', 'Decided on', 'Entschieden am', 'Décidé le'],
            'CPQ.APPROVAL.TRIGGER_MAX_LINE_DISCOUNT_PERCENT' => ['Sconto di riga oltre il limite', 'Line discount above the limit', 'Positionsrabatt über dem Limit', 'Remise de ligne au-dessus de la limite'],
            'CPQ.APPROVAL.TRIGGER_MAX_GLOBAL_DISCOUNT_PERCENT' => ['Sconto globale oltre il limite', 'Global discount above the limit', 'Gesamtrabatt über dem Limit', 'Remise globale au-dessus de la limite'],
            'CPQ.APPROVAL.TRIGGER_MIN_MARGIN_PERCENT' => ['Margine sotto il minimo', 'Margin below the minimum', 'Marge unter dem Minimum', 'Marge sous le minimum'],
            'CPQ.APPROVAL.QUEUE' => ['Coda di approvazione', 'Approval queue', 'Freigabewarteschlange', 'File d\'approbation'],
            'CPQ.APPROVAL.TIERS' => ['Livelli di approvazione', 'Approval tiers', 'Freigabestufen', 'Niveaux d\'approbation'],
            'CPQ.APPROVAL.MAX_LINE_DISCOUNT' => ['Sconto di riga massimo %', 'Max line discount %', 'Max. Positionsrabatt %', 'Remise de ligne max. %'],
            'CPQ.APPROVAL.MAX_GLOBAL_DISCOUNT' => ['Sconto globale massimo %', 'Max global discount %', 'Max. Gesamtrabatt %', 'Remise globale max. %'],
            'CPQ.APPROVAL.MIN_MARGIN' => ['Margine minimo %', 'Min margin %', 'Min. Marge %', 'Marge min. %'],
            // Preview and signature
            'CPQ.PREVIEW.TITLE' => ['Anteprima PDF', 'PDF preview', 'PDF-Vorschau', 'Aperçu PDF'],
            'CPQ.PREVIEW.LANGUAGE' => ['Lingua', 'Language', 'Sprache', 'Langue'],
            'CPQ.PREVIEW.SHOW_DETAILS' => ['Mostra dettagli tecnici e prezzi unitari', 'Show technical details and unit prices', 'Technische Details und Einzelpreise anzeigen', 'Afficher les détails techniques et les prix unitaires'],
            'CPQ.SIGN.TITLE' => ['Revisione e firma del preventivo', 'Quote review and signature', 'Prüfung und Unterschrift des Angebots', 'Révision et signature du devis'],
            'CPQ.SIGN.CREATE_LINK' => ['Crea link di firma', 'Create signature link', 'Signaturlink erstellen', 'Créer un lien de signature'],
            'CPQ.SIGN.LINK_EXPIRES' => ['Il link scade il', 'The link expires on', 'Der Link läuft ab am', 'Le lien expire le'],
            'CPQ.SIGN.COPY_LINK' => ['Copia link', 'Copy link', 'Link kopieren', 'Copier le lien'],
            'CPQ.SIGN.SIGNER_NAME' => ['Nome e cognome', 'Full name', 'Vor- und Nachname', 'Nom et prénom'],
            'CPQ.SIGN.SIGNER_ROLE' => ['Ruolo / azienda', 'Role / company', 'Funktion / Firma', 'Fonction / société'],
            'CPQ.SIGN.PO_NUMBER' => ['Numero ordine di acquisto (facoltativo)', 'Purchase order number (optional)', 'Bestellnummer (optional)', 'Numéro de bon de commande (facultatif)'],
            'CPQ.SIGN.SIGNATURE_DRAW' => ['Disegna la firma', 'Draw signature', 'Unterschrift zeichnen', 'Dessiner la signature'],
            'CPQ.SIGN.SIGNATURE_TYPE' => ['Scrivi la firma', 'Type signature', 'Unterschrift tippen', 'Saisir la signature'],
            'CPQ.SIGN.CLEAR' => ['Cancella', 'Clear', 'Löschen', 'Effacer'],
            'CPQ.SIGN.ACCEPT_TERMS' => ['Accetto il preventivo e le condizioni commerciali', 'I accept the quote and the commercial terms', 'Ich akzeptiere das Angebot und die Geschäftsbedingungen', 'J\'accepte le devis et les conditions commerciales'],
            'CPQ.SIGN.ACCEPT' => ['Firma e accetta', 'Sign and accept', 'Unterschreiben und annehmen', 'Signer et accepter'],
            'CPQ.SIGN.REQUEST_CHANGES' => ['Richiedi modifiche', 'Request changes', 'Änderungen anfragen', 'Demander des modifications'],
            'CPQ.SIGN.CHANGES_MESSAGE' => ['Cosa vuoi modificare?', 'What would you like to change?', 'Was möchten Sie ändern?', 'Que souhaitez-vous modifier ?'],
            'CPQ.SIGN.OPTIONAL_LINES' => ['Righe opzionali', 'Optional lines', 'Optionale Positionen', 'Lignes optionnelles'],
            'CPQ.SIGN.SUCCESS' => ['Preventivo firmato e accettato. Grazie!', 'Quote signed and accepted. Thank you!', 'Angebot unterzeichnet und angenommen. Vielen Dank!', 'Devis signé et accepté. Merci !'],
            'CPQ.SIGN.CHANGES_SENT' => ['Richiesta di modifica inviata', 'Change request sent', 'Änderungswunsch gesendet', 'Demande de modification envoyée'],
            'CPQ.SIGN.LINK_UNAVAILABLE' => ['Il link non è valido, è scaduto o è già stato utilizzato', 'The link is not valid, has expired or was already used', 'Der Link ist ungültig, abgelaufen oder bereits verwendet', 'Le lien n\'est pas valide, a expiré ou a déjà été utilisé'],
            'CPQ.SIGN.AUDIT' => ['Certificato di firma', 'Signature certificate', 'Signaturzertifikat', 'Certificat de signature'],
            'CPQ.SIGN.VERIFY' => ['Verifica integrità', 'Verify integrity', 'Integrität prüfen', 'Vérifier l\'intégrité'],
            'CPQ.SIGN.INTEGRITY_OK' => ['Il preventivo è identico a quello firmato', 'The quote is identical to the one signed', 'Das Angebot entspricht dem unterzeichneten', 'Le devis est identique à celui signé'],
            'CPQ.SIGN.INTEGRITY_BROKEN' => ['Il preventivo è stato modificato dopo la firma', 'The quote was changed after signing', 'Das Angebot wurde nach der Unterschrift geändert', 'Le devis a été modifié après la signature'],
            'CPQ.CONVERT.TO_ORDER' => ['Converti in ordine di vendita / fattura', 'Convert to sales order / invoice', 'In Verkaufsauftrag / Rechnung umwandeln', 'Convertir en commande de vente / facture'],
            // Quote PDF
            'PDF.QUOTE.CPQ_VAT_BREAKDOWN' => ['Ripartizione IVA', 'VAT breakdown', 'MWST-Aufstellung', 'Ventilation de la TVA'],
            'PDF.QUOTE.CPQ_VAT_RATE' => ['Aliquota', 'Rate', 'Satz', 'Taux'],
            'PDF.QUOTE.CPQ_VAT_TAXABLE' => ['Imponibile', 'Taxable amount', 'Steuerbarer Betrag', 'Base imposable'],
            'PDF.QUOTE.CPQ_VAT_AMOUNT' => ['Imposta', 'Tax', 'Steuer', 'Taxe'],
            'PDF.QUOTE.CPQ_COMMERCIAL_TERMS' => ['Condizioni commerciali', 'Commercial terms', 'Geschäftsbedingungen', 'Conditions commerciales'],
            'PDF.QUOTE.CPQ_TERMS_VALIDITY' => ['La presente offerta è valida fino alla data indicata.', 'This offer is valid until the date stated.', 'Dieses Angebot ist bis zum angegebenen Datum gültig.', 'La présente offre est valable jusqu\'à la date indiquée.'],
            'PDF.QUOTE.CPQ_TERMS_PRICES' => ['I prezzi sono espressi nella valuta indicata, IVA esclusa salvo diversa indicazione.', 'Prices are stated in the currency shown, excluding VAT unless stated otherwise.', 'Die Preise verstehen sich in der angegebenen Währung, exklusive MWST, sofern nicht anders angegeben.', 'Les prix sont indiqués dans la devise mentionnée, hors TVA sauf indication contraire.'],
            'PDF.QUOTE.CPQ_DISCLAIMER' => ['Le specifiche tecniche possono variare senza preavviso; la configurazione confermata alla firma fa fede.', 'Technical specifications may change without notice; the configuration confirmed at signature prevails.', 'Technische Spezifikationen können ohne Vorankündigung ändern; massgebend ist die bei der Unterschrift bestätigte Konfiguration.', 'Les spécifications techniques peuvent changer sans préavis ; la configuration confirmée à la signature fait foi.'],
            'PDF.QUOTE.CPQ_OPTIONAL_SELECTED' => ['opzione scelta', 'option selected', 'Option gewählt', 'option choisie'],
            'PDF.QUOTE.CPQ_OPTIONAL_AVAILABLE' => ['opzione disponibile', 'option available', 'Option verfügbar', 'option disponible'],
            'PDF.QUOTE.CPQ_PACKAGE_PRICE' => ['Prezzo a corpo', 'Package price', 'Pauschalpreis', 'Prix forfaitaire'],
            'PDF.QUOTE.CPQ_AUDIT_TITLE' => ['Certificato di firma elettronica', 'Electronic signature certificate', 'Zertifikat der elektronischen Unterschrift', 'Certificat de signature électronique'],
            'PDF.QUOTE.CPQ_AUDIT_SIGNER' => ['Firmatario', 'Signer', 'Unterzeichner', 'Signataire'],
            'PDF.QUOTE.CPQ_AUDIT_PO' => ['Ordine di acquisto', 'Purchase order', 'Bestellung', 'Bon de commande'],
            'PDF.QUOTE.CPQ_AUDIT_SIGNED_AT' => ['Data e ora', 'Date and time', 'Datum und Uhrzeit', 'Date et heure'],
            'PDF.QUOTE.CPQ_AUDIT_IP' => ['Indirizzo IP', 'IP address', 'IP-Adresse', 'Adresse IP'],
            'PDF.QUOTE.CPQ_AUDIT_HASH' => ['Impronta SHA-256 del preventivo', 'SHA-256 hash of the quote', 'SHA-256-Hash des Angebots', 'Empreinte SHA-256 du devis'],
        ];
    }
}
