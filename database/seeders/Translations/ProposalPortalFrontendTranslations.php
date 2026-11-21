<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the interactive proposal portal (Mod 036): the public page where a customer reviews a proposal, toggles optional
 * lines, picks alternatives, comments, signs with a one-time code or a qualified provider, or rejects it. Each entry is
 * [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 */
final class ProposalPortalFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'PROPOSAL.TITLE' => ['Proposta commerciale', 'Commercial proposal', 'Geschäftsangebot', 'Proposition commerciale'],
            'PROPOSAL.LINK_UNAVAILABLE' => ['Questo link non è valido, è scaduto o è stato sostituito da una nuova revisione.', 'This link is not valid, has expired or was replaced by a newer revision.', 'Dieser Link ist ungültig, abgelaufen oder wurde durch eine neuere Revision ersetzt.', 'Ce lien n’est pas valide, a expiré ou a été remplacé par une révision plus récente.'],
            'PROPOSAL.PIN_TITLE' => ['Inserisci il PIN di accesso', 'Enter the access PIN', 'Zugangs-PIN eingeben', 'Saisissez le PIN d’accès'],
            'PROPOSAL.PIN_LABEL' => ['PIN', 'PIN', 'PIN', 'PIN'],
            'PROPOSAL.PIN_SUBMIT' => ['Apri la proposta', 'Open the proposal', 'Angebot öffnen', 'Ouvrir la proposition'],
            'PROPOSAL.PIN_INVALID' => ['PIN non valido', 'The PIN is not valid', 'Die PIN ist ungültig', 'Le PIN n’est pas valide'],
            'PROPOSAL.VALID_UNTIL' => ['Valida fino al {date}', 'Valid until {date}', 'Gültig bis {date}', 'Valable jusqu’au {date}'],
            'PROPOSAL.COL_DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'PROPOSAL.COL_QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'PROPOSAL.COL_UNIT_PRICE' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'PROPOSAL.COL_NET' => ['Importo netto', 'Net amount', 'Nettobetrag', 'Montant net'],
            'PROPOSAL.SECTION_SUBTOTAL' => ['Subtotale sezione', 'Section subtotal', 'Zwischensumme Abschnitt', 'Sous-total de la section'],
            'PROPOSAL.OPTIONAL' => ['Opzionale', 'Optional', 'Optional', 'Optionnel'],
            'PROPOSAL.ALTERNATIVE' => ['Alternativa', 'Alternative', 'Alternative', 'Alternative'],
            'PROPOSAL.TOTAL_NET' => ['Totale netto', 'Net total', 'Nettosumme', 'Total net'],
            'PROPOSAL.TOTAL_TAX' => ['IVA / imposta', 'VAT / tax', 'MwSt. / Steuer', 'TVA / taxe'],
            'PROPOSAL.TOTAL_ROUNDING' => ['Arrotondamento', 'Rounding', 'Rundung', 'Arrondi'],
            'PROPOSAL.TOTAL_PAYABLE' => ['Totale da pagare', 'Total payable', 'Zu zahlender Betrag', 'Total à payer'],
            'PROPOSAL.TAX_RATE' => ['Aliquota {rate}%', 'Rate {rate}%', 'Satz {rate}%', 'Taux {rate}%'],
            'PROPOSAL.RECALCULATING' => ['Ricalcolo in corso…', 'Recalculating…', 'Wird neu berechnet…', 'Recalcul en cours…'],
            'PROPOSAL.DOWNLOAD_PROPOSAL' => ['Scarica la proposta firmata (PDF/A)', 'Download the signed proposal (PDF/A)', 'Signiertes Angebot herunterladen (PDF/A)', 'Télécharger la proposition signée (PDF/A)'],
            'PROPOSAL.DOWNLOAD_CERTIFICATE' => ['Scarica il certificato di completamento', 'Download the certificate of completion', 'Abschlusszertifikat herunterladen', 'Télécharger le certificat d’achèvement'],
            'PROPOSAL.COMMENT_TITLE' => ['Domande al venditore', 'Questions for the sales rep', 'Fragen an den Verkäufer', 'Questions au commercial'],
            'PROPOSAL.COMMENT_PLACEHOLDER' => ['Scrivi la tua domanda', 'Write your question', 'Frage eingeben', 'Écrivez votre question'],
            'PROPOSAL.COMMENT_SEND' => ['Invia', 'Send', 'Senden', 'Envoyer'],
            'PROPOSAL.SIGN' => ['Accetta e firma', 'Accept and sign', 'Annehmen und signieren', 'Accepter et signer'],
            'PROPOSAL.SIGN_NAME' => ['Nome e cognome del firmatario', 'Signer full name', 'Name des Unterzeichners', 'Nom du signataire'],
            'PROPOSAL.SIGN_ROLE' => ['Ruolo o titolo', 'Role or title', 'Funktion oder Titel', 'Fonction ou titre'],
            'PROPOSAL.SIGN_CONSENT' => ['Accetto la proposta e acconsento alla firma elettronica', 'I accept the proposal and consent to sign electronically', 'Ich nehme das Angebot an und stimme der elektronischen Signatur zu', 'J’accepte la proposition et consens à la signature électronique'],
            'PROPOSAL.SIGN_CHANNEL' => ['Ricevi il codice via', 'Receive the code by', 'Code erhalten per', 'Recevoir le code par'],
            'PROPOSAL.SIGN_CHANNEL_EMAIL' => ['Email', 'Email', 'E-Mail', 'E-mail'],
            'PROPOSAL.SIGN_CHANNEL_SMS' => ['SMS', 'SMS', 'SMS', 'SMS'],
            'PROPOSAL.SIGN_REQUEST_CODE' => ['Invia il codice', 'Send the code', 'Code senden', 'Envoyer le code'],
            'PROPOSAL.SIGN_CODE' => ['Codice a 6 cifre', '6-digit code', '6-stelliger Code', 'Code à 6 chiffres'],
            'PROPOSAL.SIGN_CONFIRM' => ['Conferma la firma', 'Confirm the signature', 'Signatur bestätigen', 'Confirmer la signature'],
            'PROPOSAL.SIGN_CODE_INVALID' => ['Codice non valido o scaduto', 'The code is not valid or has expired', 'Der Code ist ungültig oder abgelaufen', 'Le code n’est pas valide ou a expiré'],
            'PROPOSAL.SIGN_QUALIFIED' => ['Avvia la firma qualificata presso il fornitore di servizi fiduciari', 'Start the qualified signature with the trust service provider', 'Qualifizierte Signatur beim Vertrauensdiensteanbieter starten', 'Lancer la signature qualifiée chez le prestataire de services de confiance'],
            'PROPOSAL.SIGN_WAITING' => ['In attesa della conferma del fornitore…', 'Waiting for the provider’s confirmation…', 'Warten auf die Bestätigung des Anbieters…', 'En attente de la confirmation du prestataire…'],
            'PROPOSAL.SIGNED' => ['Proposta accettata e firmata. Grazie!', 'Proposal accepted and signed. Thank you!', 'Angebot angenommen und signiert. Vielen Dank!', 'Proposition acceptée et signée. Merci !'],
            'PROPOSAL.REJECT' => ['Rifiuta la proposta', 'Reject the proposal', 'Angebot ablehnen', 'Refuser la proposition'],
            'PROPOSAL.REJECT_REASON' => ['Motivo del rifiuto (obbligatorio)', 'Reason for rejection (required)', 'Ablehnungsgrund (erforderlich)', 'Motif du refus (obligatoire)'],
            'PROPOSAL.REJECT_CONFIRM' => ['Conferma il rifiuto', 'Confirm the rejection', 'Ablehnung bestätigen', 'Confirmer le refus'],
            'PROPOSAL.REJECTED' => ['La proposta è stata rifiutata.', 'The proposal was rejected.', 'Das Angebot wurde abgelehnt.', 'La proposition a été refusée.'],
            'PROPOSAL.ERROR' => ['Operazione non riuscita, riprova', 'The operation failed, please try again', 'Der Vorgang ist fehlgeschlagen, bitte erneut versuchen', 'L’opération a échoué, veuillez réessayer'],
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
