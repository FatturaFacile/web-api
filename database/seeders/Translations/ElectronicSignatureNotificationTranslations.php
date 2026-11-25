<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * Texts the electronic signature module (Issue #211) writes itself, read by SignatureTextResolver and served to the signing
 * portal: the notification mails and the SMS code, the certificate of completion, the stamp drawn next to a signature, the
 * documents rendered for purchase orders and employment contracts, the consents the signer accepts, the archive entries and
 * the error codes of the API (`signatures.error.<code>`).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Placeholders are `:name`, as in the backend dictionaries. Legal acronyms (FES, FEA, FEQ, eIDAS, ZertES) stay untranslated.
 */
final class ElectronicSignatureNotificationTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'signatures.mail.request.subject' => ['Richiesta di firma: :title', 'Signature request: :title', 'Signaturanfrage: :title', 'Demande de signature : :title'],
            'signatures.mail.request.body' => [
                "Buongiorno :name,\n:sender ti ha inviato il documento «:title» da firmare (livello :level).\n:message\nLa richiesta scade il :expires.",
                "Hello :name,\n:sender has sent you the document “:title” to sign (level :level).\n:message\nThe request expires on :expires.",
                "Guten Tag :name\n:sender hat Ihnen das Dokument «:title» zur Unterschrift gesendet (Stufe :level).\n:message\nDie Anfrage läuft am :expires ab.",
                "Bonjour :name,\n:sender vous a envoyé le document « :title » à signer (niveau :level).\n:message\nLa demande expire le :expires.",
            ],
            'signatures.mail.request.action' => ['Apri il documento e firma', 'Open the document and sign', 'Dokument öffnen und unterzeichnen', 'Ouvrir le document et signer'],
            'signatures.mail.reminder.subject' => ['Promemoria: firma richiesta per :title', 'Reminder: signature requested for :title', 'Erinnerung: Unterschrift für :title erbeten', 'Rappel : signature demandée pour :title'],
            'signatures.mail.reminder.body' => [
                "Buongiorno :name,\nil documento «:title» attende ancora la tua firma.\n:message\nLa richiesta scade il :expires.",
                "Hello :name,\nthe document “:title” is still waiting for your signature.\n:message\nThe request expires on :expires.",
                "Guten Tag :name\ndas Dokument «:title» wartet noch auf Ihre Unterschrift.\n:message\nDie Anfrage läuft am :expires ab.",
                "Bonjour :name,\nle document « :title » attend toujours votre signature.\n:message\nLa demande expire le :expires.",
            ],
            'signatures.mail.reminder.action' => ['Apri il documento e firma', 'Open the document and sign', 'Dokument öffnen und unterzeichnen', 'Ouvrir le document et signer'],
            'signatures.mail.completed.subject' => ['Documento firmato: :title', 'Document signed: :title', 'Dokument unterzeichnet: :title', 'Document signé : :title'],
            'signatures.mail.completed.body' => [
                "Buongiorno :name,\nil documento «:title» è stato firmato da tutti. In allegato trovi il documento firmato e il certificato di completamento.\nCodice di verifica: :code",
                "Hello :name,\nthe document “:title” has been signed by everyone. The signed document and the certificate of completion are attached.\nVerification code: :code",
                "Guten Tag :name\ndas Dokument «:title» wurde von allen unterzeichnet. Im Anhang finden Sie das signierte Dokument und das Abschlusszertifikat.\nPrüfcode: :code",
                "Bonjour :name,\nle document « :title » a été signé par tous. Vous trouverez en pièce jointe le document signé et le certificat d’achèvement.\nCode de vérification : :code",
            ],
            'signatures.mail.completed.action' => ['Verifica la firma', 'Verify the signature', 'Signatur prüfen', 'Vérifier la signature'],
            'signatures.mail.declined.subject' => ['Firma rifiutata: :title', 'Signature declined: :title', 'Unterschrift abgelehnt: :title', 'Signature refusée : :title'],
            'signatures.mail.declined.body' => [
                ":name ha rifiutato di firmare il documento «:title».\nMotivo: :reason",
                ":name declined to sign the document “:title”.\nReason: :reason",
                ":name hat die Unterschrift unter das Dokument «:title» abgelehnt.\nGrund: :reason",
                ":name a refusé de signer le document « :title ».\nMotif : :reason",
            ],
            'signatures.mail.expired.subject' => ['Richiesta di firma scaduta: :title', 'Signature request expired: :title', 'Signaturanfrage abgelaufen: :title', 'Demande de signature expirée : :title'],
            'signatures.mail.expired.body' => [
                'La richiesta di firma per «:title» è scaduta prima che tutti firmassero. Puoi crearne una nuova dal centro firme.',
                'The signature request for “:title” expired before everyone signed. You can create a new one from the signature center.',
                'Die Signaturanfrage für «:title» ist abgelaufen, bevor alle unterzeichnet haben. Sie können im Signaturzentrum eine neue erstellen.',
                'La demande de signature pour « :title » a expiré avant que tout le monde ait signé. Vous pouvez en créer une nouvelle depuis le centre de signatures.',
            ],
            'signatures.mail.footer' => ['Messaggio automatico: non rispondere a questa e-mail.', 'Automatic message: please do not reply to this email.', 'Automatische Nachricht: Bitte antworten Sie nicht auf diese E-Mail.', 'Message automatique : merci de ne pas répondre à cet e-mail.'],
            'signatures.sms.otp' => ['Codice di firma :code (valido :minutes minuti). Non comunicarlo a nessuno.', 'Signing code :code (valid for :minutes minutes). Do not share it.', 'Signaturcode :code (:minutes Minuten gültig). Geben Sie ihn nicht weiter.', 'Code de signature :code (valable :minutes minutes). Ne le communiquez à personne.'],

            'signatures.consent.terms' => ['Ho letto il documento e accetto di firmarlo elettronicamente; riconosco che la mia firma è registrata insieme a data, ora, indirizzo IP e dispositivo.', 'I have read the document and agree to sign it electronically; I acknowledge that my signature is recorded with the date, time, IP address and device.', 'Ich habe das Dokument gelesen und bin mit der elektronischen Unterzeichnung einverstanden; ich nehme zur Kenntnis, dass meine Unterschrift mit Datum, Uhrzeit, IP-Adresse und Gerät festgehalten wird.', 'J’ai lu le document et j’accepte de le signer électroniquement ; je reconnais que ma signature est enregistrée avec la date, l’heure, l’adresse IP et l’appareil.'],
            'signatures.consent.legal.FES.eIDAS' => ['Capisco che si tratta di una firma elettronica semplice ai sensi del regolamento eIDAS (UE) n. 910/2014, art. 3 n. 10.', 'I understand this is a simple electronic signature under eIDAS Regulation (EU) No 910/2014, art. 3(10).', 'Mir ist bewusst, dass es sich um eine einfache elektronische Signatur im Sinne der eIDAS-Verordnung (EU) Nr. 910/2014, Art. 3 Nr. 10 handelt.', 'Je comprends qu’il s’agit d’une signature électronique simple au sens du règlement eIDAS (UE) n° 910/2014, art. 3, point 10.'],
            'signatures.consent.legal.FES.ZertES' => ['Capisco che si tratta di una firma elettronica semplice ai sensi dell’art. 14 cpv. 1 CO, non equiparata alla firma autografa.', 'I understand this is a simple electronic signature under art. 14 para. 1 CO, not equivalent to a handwritten signature.', 'Mir ist bewusst, dass es sich um eine einfache elektronische Signatur nach Art. 14 Abs. 1 OR handelt, die der eigenhändigen Unterschrift nicht gleichgestellt ist.', 'Je comprends qu’il s’agit d’une signature électronique simple au sens de l’art. 14 al. 1 CO, non assimilée à la signature manuscrite.'],
            'signatures.consent.legal.FEA.eIDAS' => ['Capisco che si tratta di una firma elettronica avanzata ai sensi del regolamento eIDAS (UE) n. 910/2014, art. 26, collegata in modo univoco a me.', 'I understand this is an advanced electronic signature under eIDAS Regulation (EU) No 910/2014, art. 26, uniquely linked to me.', 'Mir ist bewusst, dass es sich um eine fortgeschrittene elektronische Signatur nach eIDAS-Verordnung (EU) Nr. 910/2014, Art. 26 handelt, die eindeutig mir zugeordnet ist.', 'Je comprends qu’il s’agit d’une signature électronique avancée au sens du règlement eIDAS (UE) n° 910/2014, art. 26, liée de façon univoque à moi.'],
            'signatures.consent.legal.FEA.ZertES' => ['Capisco che si tratta di una firma elettronica avanzata ai sensi della ZertES: prova valida, ma non equiparata alla firma autografa per gli atti che richiedono la forma scritta.', 'I understand this is an advanced electronic signature under ZertES: valid evidence, but not equivalent to a handwritten signature for acts that require written form.', 'Mir ist bewusst, dass es sich um eine fortgeschrittene elektronische Signatur nach ZertES handelt: gültiger Beweis, aber für Rechtsakte mit Schriftform der eigenhändigen Unterschrift nicht gleichgestellt.', 'Je comprends qu’il s’agit d’une signature électronique avancée au sens de la SCSE : preuve valable, mais non assimilée à la signature manuscrite pour les actes soumis à la forme écrite.'],
            'signatures.consent.legal.FEQ.eIDAS' => ['Capisco che si tratta di una firma elettronica qualificata ai sensi del regolamento eIDAS (UE) n. 910/2014, art. 25 par. 2: ha l’effetto giuridico di una firma autografa.', 'I understand this is a qualified electronic signature under eIDAS Regulation (EU) No 910/2014, art. 25(2): it has the legal effect of a handwritten signature.', 'Mir ist bewusst, dass es sich um eine qualifizierte elektronische Signatur nach eIDAS-Verordnung (EU) Nr. 910/2014, Art. 25 Abs. 2 handelt: Sie hat die Rechtswirkung einer eigenhändigen Unterschrift.', 'Je comprends qu’il s’agit d’une signature électronique qualifiée au sens du règlement eIDAS (UE) n° 910/2014, art. 25, par. 2 : elle a l’effet juridique d’une signature manuscrite.'],
            'signatures.consent.legal.FEQ.ZertES' => ['Capisco che si tratta di una firma elettronica qualificata con marca temporale qualificata ai sensi della ZertES e dell’art. 14 cpv. 2bis CO: equivale alla firma autografa.', 'I understand this is a qualified electronic signature with a qualified timestamp under ZertES and art. 14 para. 2bis CO: it is equivalent to a handwritten signature.', 'Mir ist bewusst, dass es sich um eine qualifizierte elektronische Signatur mit qualifiziertem Zeitstempel nach ZertES und Art. 14 Abs. 2bis OR handelt: Sie ist der eigenhändigen Unterschrift gleichgestellt.', 'Je comprends qu’il s’agit d’une signature électronique qualifiée avec horodatage qualifié au sens de la SCSE et de l’art. 14 al. 2bis CO : elle est assimilée à la signature manuscrite.'],

            'signatures.jurisdiction.eIDAS' => ['eIDAS (UE)', 'eIDAS (EU)', 'eIDAS (EU)', 'eIDAS (UE)'],
            'signatures.jurisdiction.ZertES' => ['ZertES (Svizzera)', 'ZertES (Switzerland)', 'ZertES (Schweiz)', 'ZertES (Suisse)'],
            'signatures.level.FES' => ['FES: firma elettronica semplice', 'FES: simple electronic signature', 'FES: einfache elektronische Signatur', 'FES : signature électronique simple'],
            'signatures.level.FEA' => ['FEA: firma elettronica avanzata', 'FEA: advanced electronic signature', 'FEA: fortgeschrittene elektronische Signatur', 'FEA : signature électronique avancée'],
            'signatures.level.FEQ' => ['FEQ: firma elettronica qualificata', 'FEQ: qualified electronic signature', 'FEQ: qualifizierte elektronische Signatur', 'FEQ : signature électronique qualifiée'],

            'signatures.cert.title' => ['Documento', 'Document', 'Dokument', 'Document'],
            'signatures.cert.heading' => ['Certificato di completamento della firma elettronica', 'Certificate of completion of the electronic signature', 'Abschlusszertifikat der elektronischen Signatur', 'Certificat d’achèvement de la signature électronique'],
            'signatures.cert.code' => ['Codice di verifica', 'Verification code', 'Prüfcode', 'Code de vérification'],
            'signatures.cert.level' => ['Livello di firma', 'Signature level', 'Signaturstufe', 'Niveau de signature'],
            'signatures.cert.jurisdiction' => ['Giurisdizione', 'Jurisdiction', 'Rechtsraum', 'Juridiction'],
            'signatures.cert.provider' => ['Provider', 'Provider', 'Anbieter', 'Fournisseur'],
            'signatures.cert.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'signatures.cert.created' => ['Creato il', 'Created on', 'Erstellt am', 'Créé le'],
            'signatures.cert.completed' => ['Completato il', 'Completed on', 'Abgeschlossen am', 'Terminé le'],
            'signatures.cert.originalHash' => ['SHA-256 del documento originale', 'SHA-256 of the original document', 'SHA-256 des Originaldokuments', 'SHA-256 du document original'],
            'signatures.cert.signedHash' => ['SHA-256 del documento firmato', 'SHA-256 of the signed document', 'SHA-256 des signierten Dokuments', 'SHA-256 du document signé'],
            'signatures.cert.verifyAt' => ['Verifica online', 'Verify online', 'Online prüfen', 'Vérifier en ligne'],
            'signatures.cert.signers' => ['Firmatari', 'Signers', 'Unterzeichnende', 'Signataires'],
            'signatures.cert.trail' => ['Registro di audit', 'Audit trail', 'Prüfprotokoll', 'Journal d’audit'],
            'signatures.cert.event' => ['Evento', 'Event', 'Ereignis', 'Événement'],
            'signatures.cert.when' => ['Data e ora (UTC)', 'Date and time (UTC)', 'Datum und Zeit (UTC)', 'Date et heure (UTC)'],
            'signatures.cert.actor' => ['Attore', 'Actor', 'Akteur', 'Acteur'],
            'signatures.cert.method' => ['Metodo di identificazione', 'Identification method', 'Identifikationsmethode', 'Méthode d’identification'],
            'signatures.cert.chain' => ['Integrità del registro', 'Audit trail integrity', 'Integrität des Prüfprotokolls', 'Intégrité du journal d’audit'],
            'signatures.cert.chainValid' => ['Catena di impronte verificata', 'Hash chain verified', 'Hash-Kette geprüft', 'Chaîne d’empreintes vérifiée'],
            'signatures.cert.chainBroken' => ['Catena di impronte alterata', 'Hash chain broken', 'Hash-Kette unterbrochen', 'Chaîne d’empreintes rompue'],
            'signatures.cert.disclaimer' => ['Questo certificato attesta gli eventi registrati dalla piattaforma. Il valore giuridico della firma dipende dal livello indicato (FES, FEA, FEQ) e dalla giurisdizione applicabile (eIDAS, ZertES).', 'This certificate attests the events recorded by the platform. The legal value of the signature depends on the level shown (FES, FEA, FEQ) and the applicable jurisdiction (eIDAS, ZertES).', 'Dieses Zertifikat bestätigt die von der Plattform erfassten Ereignisse. Die Rechtswirkung der Signatur hängt von der angegebenen Stufe (FES, FEA, FEQ) und vom anwendbaren Rechtsraum (eIDAS, ZertES) ab.', 'Ce certificat atteste les événements enregistrés par la plateforme. La valeur juridique de la signature dépend du niveau indiqué (FES, FEA, FEQ) et de la juridiction applicable (eIDAS, ZertES).'],
            'signatures.cert.footer' => ['Generato automaticamente: la verifica è possibile con il codice di verifica indicato.', 'Generated automatically: it can be verified with the verification code shown.', 'Automatisch erstellt: Die Prüfung ist mit dem angegebenen Prüfcode möglich.', 'Généré automatiquement : la vérification est possible avec le code de vérification indiqué.'],

            'signatures.stamp.level' => ['Firma elettronica :level (:jurisdiction)', 'Electronic signature :level (:jurisdiction)', 'Elektronische Signatur :level (:jurisdiction)', 'Signature électronique :level (:jurisdiction)'],
            'signatures.stamp.signedAt' => ['Firmato il :date', 'Signed on :date', 'Unterzeichnet am :date', 'Signé le :date'],
            'signatures.stamp.certificate' => ['Certificato n. :serial', 'Certificate no. :serial', 'Zertifikat Nr. :serial', 'Certificat n° :serial'],
            'signatures.stamp.verify' => ['Verifica: :code', 'Verify: :code', 'Prüfen: :code', 'Vérifier : :code'],
            'signatures.stamp.reason' => ['Firma del documento «:title»', 'Signature of the document “:title”', 'Unterschrift des Dokuments «:title»', 'Signature du document « :title »'],

            'signatures.archive.comment' => ['Documento firmato elettronicamente. Codice di verifica :code.', 'Electronically signed document. Verification code :code.', 'Elektronisch signiertes Dokument. Prüfcode :code.', 'Document signé électroniquement. Code de vérification :code.'],
            'signatures.archive.certificate' => ['Certificato di completamento', 'Certificate of completion', 'Abschlusszertifikat', 'Certificat d’achèvement'],

            'signatures.doc.number' => ['Numero', 'Number', 'Nummer', 'Numéro'],
            'signatures.doc.date' => ['Data', 'Date', 'Datum', 'Date'],
            'signatures.doc.delivery' => ['Consegna prevista', 'Expected delivery', 'Voraussichtliche Lieferung', 'Livraison prévue'],
            'signatures.doc.total' => ['Totale', 'Total', 'Total', 'Total'],
            'signatures.doc.supplier' => ['Fornitore', 'Supplier', 'Lieferant', 'Fournisseur'],
            'signatures.doc.purchaseOrder' => ['Ordine d’acquisto', 'Purchase order', 'Bestellung', 'Commande d’achat'],
            'signatures.doc.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'signatures.doc.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'signatures.doc.unitPrice' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'signatures.doc.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'signatures.doc.employmentContract' => ['Contratto di lavoro', 'Employment contract', 'Arbeitsvertrag', 'Contrat de travail'],
            'signatures.doc.employee' => ['Dipendente', 'Employee', 'Mitarbeitende Person', 'Employé'],
            'signatures.doc.contractType' => ['Tipo di contratto', 'Contract type', 'Vertragsart', 'Type de contrat'],
            'signatures.doc.jobTitle' => ['Funzione', 'Job title', 'Funktion', 'Fonction'],
            'signatures.doc.startDate' => ['Inizio', 'Start date', 'Beginn', 'Début'],
            'signatures.doc.endDate' => ['Fine', 'End date', 'Ende', 'Fin'],
            'signatures.doc.weeklyHours' => ['Ore settimanali', 'Weekly hours', 'Wochenstunden', 'Heures hebdomadaires'],
            'signatures.doc.salary' => ['Retribuzione annua lorda', 'Gross annual salary', 'Jahresbruttolohn', 'Salaire annuel brut'],
            'signatures.doc.agreement' => ['Contratto collettivo', 'Collective agreement', 'Gesamtarbeitsvertrag', 'Convention collective'],
            'signatures.doc.footer' => ['Documento generato dalla piattaforma per la firma elettronica.', 'Document generated by the platform for electronic signature.', 'Von der Plattform für die elektronische Signatur erzeugtes Dokument.', 'Document généré par la plateforme pour la signature électronique.'],

            'signatures.message.callbackAccepted' => ['Notifica del provider accettata', 'Provider notification accepted', 'Anbieter-Benachrichtigung akzeptiert', 'Notification du fournisseur acceptée'],
            'signatures.message.challengeSent' => ['Codice di verifica inviato', 'Verification code sent', 'Bestätigungscode gesendet', 'Code de vérification envoyé'],
            'signatures.message.declined' => ['Hai rifiutato di firmare', 'You declined to sign', 'Sie haben die Unterschrift abgelehnt', 'Vous avez refusé de signer'],
            'signatures.message.listed' => ['Richieste di firma', 'Signature requests', 'Signaturanfragen', 'Demandes de signature'],
            'signatures.message.signed' => ['Documento firmato', 'Document signed', 'Dokument unterzeichnet', 'Document signé'],
            'signatures.message.signingPending' => ['Firma in attesa di conferma dal provider', 'Signature waiting for provider confirmation', 'Unterschrift wartet auf die Bestätigung des Anbieters', 'Signature en attente de confirmation du fournisseur'],
            'signatures.message.verified' => ['Verifica completata', 'Verification completed', 'Prüfung abgeschlossen', 'Vérification terminée'],
            'signatures.message.verifyNotFound' => ['Nessun documento corrisponde', 'No document matches', 'Kein Dokument stimmt überein', 'Aucun document ne correspond'],

            'signatures.error.generic' => ['Operazione non riuscita. Riprova tra poco.', 'The operation failed. Please try again shortly.', 'Der Vorgang ist fehlgeschlagen. Bitte versuchen Sie es in Kürze erneut.', 'L’opération a échoué. Veuillez réessayer dans un instant.'],
            'signatures.error.already_signed' => ['Hai già firmato questo documento.', 'You have already signed this document.', 'Sie haben dieses Dokument bereits unterzeichnet.', 'Vous avez déjà signé ce document.'],
            'signatures.error.auth_method_not_supported' => ['Il provider scelto non supporta questo metodo di identificazione.', 'The chosen provider does not support this identification method.', 'Der gewählte Anbieter unterstützt diese Identifikationsmethode nicht.', 'Le fournisseur choisi ne prend pas en charge cette méthode d’identification.'],
            'signatures.error.certificate_unreadable' => ['Il certificato PKCS#12 non può essere letto: controlla il file e la password.', 'The PKCS#12 certificate cannot be read: check the file and the password.', 'Das PKCS#12-Zertifikat kann nicht gelesen werden: Prüfen Sie Datei und Passwort.', 'Le certificat PKCS#12 est illisible : vérifiez le fichier et le mot de passe.'],
            'signatures.error.consent_required' => ['Per firmare devi accettare entrambe le dichiarazioni.', 'You must accept both statements to sign.', 'Zum Unterzeichnen müssen Sie beide Erklärungen akzeptieren.', 'Pour signer, vous devez accepter les deux déclarations.'],
            'signatures.error.credentials_missing' => ['Mancano le credenziali del provider.', 'The provider credentials are missing.', 'Die Zugangsdaten des Anbieters fehlen.', 'Les identifiants du fournisseur sont manquants.'],
            'signatures.error.decline_reason_required' => ['Indica il motivo del rifiuto.', 'Please give the reason for declining.', 'Bitte geben Sie den Grund der Ablehnung an.', 'Indiquez le motif du refus.'],
            'signatures.error.document_has_no_file' => ['Il documento non ha un file da firmare.', 'The document has no file to sign.', 'Das Dokument enthält keine zu unterzeichnende Datei.', 'Le document ne contient aucun fichier à signer.'],
            'signatures.error.document_missing' => ['Il documento da firmare non è più disponibile.', 'The document to sign is no longer available.', 'Das zu unterzeichnende Dokument ist nicht mehr verfügbar.', 'Le document à signer n’est plus disponible.'],
            'signatures.error.document_not_found' => ['Documento non trovato.', 'Document not found.', 'Dokument nicht gefunden.', 'Document introuvable.'],
            'signatures.error.document_too_large' => ['Il documento supera la dimensione massima consentita.', 'The document exceeds the maximum allowed size.', 'Das Dokument überschreitet die maximal zulässige Grösse.', 'Le document dépasse la taille maximale autorisée.'],
            'signatures.error.duplicate_recipient' => ['Ogni destinatario può comparire una sola volta.', 'Each recipient can appear only once.', 'Jeder Empfänger darf nur einmal vorkommen.', 'Chaque destinataire ne peut apparaître qu’une seule fois.'],
            'signatures.error.envelope_already_open' => ['Per questo documento esiste già una richiesta di firma in corso.', 'A signature request is already open for this document.', 'Für dieses Dokument läuft bereits eine Signaturanfrage.', 'Une demande de signature est déjà en cours pour ce document.'],
            'signatures.error.envelope_busy' => ['La richiesta è in elaborazione: riprova tra un istante.', 'The request is being processed: try again in a moment.', 'Die Anfrage wird gerade bearbeitet: Bitte versuchen Sie es gleich erneut.', 'La demande est en cours de traitement : réessayez dans un instant.'],
            'signatures.error.envelope_closed' => ['La richiesta di firma è già conclusa.', 'The signature request is already closed.', 'Die Signaturanfrage ist bereits abgeschlossen.', 'La demande de signature est déjà clôturée.'],
            'signatures.error.envelope_expired' => ['La richiesta di firma è scaduta.', 'The signature request has expired.', 'Die Signaturanfrage ist abgelaufen.', 'La demande de signature a expiré.'],
            'signatures.error.envelope_not_draft' => ['Solo una bozza può essere modificata o inviata.', 'Only a draft can be edited or sent.', 'Nur ein Entwurf kann bearbeitet oder versendet werden.', 'Seul un brouillon peut être modifié ou envoyé.'],
            'signatures.error.expiry_in_past' => ['La scadenza deve essere nel futuro.', 'The expiry must be in the future.', 'Der Ablauf muss in der Zukunft liegen.', 'L’expiration doit être dans le futur.'],
            'signatures.error.file_required' => ['Carica il file da firmare.', 'Upload the file to sign.', 'Laden Sie die zu unterzeichnende Datei hoch.', 'Téléversez le fichier à signer.'],
            'signatures.error.format_not_supported' => ['Questo provider non supporta il formato del documento.', 'This provider does not support the document format.', 'Dieser Anbieter unterstützt das Dokumentformat nicht.', 'Ce fournisseur ne prend pas en charge le format du document.'],
            'signatures.error.forbidden' => ['Non hai i permessi per questa operazione.', 'You do not have permission for this operation.', 'Sie haben keine Berechtigung für diesen Vorgang.', 'Vous n’avez pas l’autorisation d’effectuer cette opération.'],
            'signatures.error.identity_document_required' => ['Indica tipo e numero del documento d’identità.', 'Enter the type and number of your identity document.', 'Geben Sie Art und Nummer Ihres Ausweisdokuments an.', 'Indiquez le type et le numéro de votre document d’identité.'],
            'signatures.error.level_below_policy' => ['Il livello scelto è inferiore al minimo richiesto per questo documento.', 'The chosen level is below the minimum required for this document.', 'Die gewählte Stufe liegt unter dem für dieses Dokument erforderlichen Minimum.', 'Le niveau choisi est inférieur au minimum requis pour ce document.'],
            'signatures.error.link_invalid' => ['Il link non è valido o non è più utilizzabile.', 'The link is not valid or can no longer be used.', 'Der Link ist ungültig oder kann nicht mehr verwendet werden.', 'Le lien n’est pas valide ou ne peut plus être utilisé.'],
            'signatures.error.no_provider_available' => ['Nessun provider attivo può rilasciare il livello richiesto per questa giurisdizione.', 'No active provider can issue the required level for this jurisdiction.', 'Kein aktiver Anbieter kann die erforderliche Stufe für diesen Rechtsraum ausstellen.', 'Aucun fournisseur actif ne peut délivrer le niveau requis pour cette juridiction.'],
            'signatures.error.no_signer' => ['Aggiungi almeno un firmatario.', 'Add at least one signer.', 'Fügen Sie mindestens eine unterzeichnende Person hinzu.', 'Ajoutez au moins un signataire.'],
            'signatures.error.not_your_turn' => ['Non è ancora il tuo turno: attendi che i firmatari precedenti abbiano firmato.', 'It is not your turn yet: wait until the previous signers have signed.', 'Sie sind noch nicht an der Reihe: Warten Sie, bis die vorherigen Unterzeichnenden unterschrieben haben.', 'Ce n’est pas encore votre tour : attendez que les signataires précédents aient signé.'],
            'signatures.error.nothing_pending' => ['Non ci sono firme in attesa.', 'There are no signatures pending.', 'Es stehen keine Unterschriften aus.', 'Aucune signature n’est en attente.'],
            'signatures.error.nothing_to_remind' => ['Nessun destinatario da sollecitare.', 'No recipient to remind.', 'Kein Empfänger zum Erinnern.', 'Aucun destinataire à relancer.'],
            'signatures.error.otp_expired' => ['Il codice è scaduto: richiedine uno nuovo.', 'The code has expired: request a new one.', 'Der Code ist abgelaufen: Fordern Sie einen neuen an.', 'Le code a expiré : demandez-en un nouveau.'],
            'signatures.error.otp_invalid' => ['Il codice non è corretto.', 'The code is not correct.', 'Der Code ist nicht korrekt.', 'Le code n’est pas correct.'],
            'signatures.error.otp_locked' => ['Troppi tentativi errati: richiedi un nuovo codice più tardi.', 'Too many wrong attempts: request a new code later.', 'Zu viele Fehlversuche: Fordern Sie später einen neuen Code an.', 'Trop de tentatives erronées : demandez un nouveau code plus tard.'],
            'signatures.error.parallel_not_supported_by_provider' => ['Questo provider firma solo in sequenza: scegli il flusso in sequenza.', 'This provider signs sequentially only: choose the sequential flow.', 'Dieser Anbieter signiert nur nacheinander: Wählen Sie den sequenziellen Ablauf.', 'Ce fournisseur ne signe que de façon séquentielle : choisissez le flux séquentiel.'],
            'signatures.error.phone_required' => ['Serve un numero di cellulare in formato internazionale.', 'A mobile number in international format is required.', 'Eine Mobilnummer im internationalen Format ist erforderlich.', 'Un numéro de mobile au format international est requis.'],
            'signatures.error.provider_cannot_issue_level' => ['Il provider scelto non può rilasciare il livello richiesto.', 'The chosen provider cannot issue the required level.', 'Der gewählte Anbieter kann die erforderliche Stufe nicht ausstellen.', 'Le fournisseur choisi ne peut pas délivrer le niveau requis.'],
            'signatures.error.provider_in_use' => ['Il provider è usato da richieste di firma in corso e non può essere eliminato.', 'The provider is used by open signature requests and cannot be deleted.', 'Der Anbieter wird von offenen Signaturanfragen verwendet und kann nicht gelöscht werden.', 'Le fournisseur est utilisé par des demandes de signature en cours et ne peut pas être supprimé.'],
            'signatures.error.provider_not_asynchronous' => ['Questo provider non invia conferme asincrone.', 'This provider does not send asynchronous confirmations.', 'Dieser Anbieter sendet keine asynchronen Bestätigungen.', 'Ce fournisseur n’envoie pas de confirmations asynchrones.'],
            'signatures.error.provider_not_found' => ['Provider non trovato.', 'Provider not found.', 'Anbieter nicht gefunden.', 'Fournisseur introuvable.'],
            'signatures.error.provider_rejected' => ['Il provider ha rifiutato la richiesta di firma.', 'The provider rejected the signature request.', 'Der Anbieter hat die Signaturanfrage abgelehnt.', 'Le fournisseur a refusé la demande de signature.'],
            'signatures.error.provider_unavailable' => ['Il provider non è raggiungibile: riprova più tardi.', 'The provider cannot be reached: try again later.', 'Der Anbieter ist nicht erreichbar: Versuchen Sie es später erneut.', 'Le fournisseur est injoignable : réessayez plus tard.'],
            'signatures.error.seal_failed' => ['Non è stato possibile sigillare il documento.', 'The document could not be sealed.', 'Das Dokument konnte nicht versiegelt werden.', 'Le document n’a pas pu être scellé.'],
            'signatures.error.sign_at_provider' => ['Questa firma si completa sul sito del provider.', 'This signature is completed on the provider site.', 'Diese Unterschrift wird auf der Seite des Anbieters abgeschlossen.', 'Cette signature se termine sur le site du fournisseur.'],
            'signatures.error.signature_too_large' => ['La firma supera lo spazio riservato nel documento.', 'The signature exceeds the space reserved in the document.', 'Die Signatur überschreitet den im Dokument reservierten Platz.', 'La signature dépasse l’espace réservé dans le document.'],
            'signatures.error.sms_unavailable' => ['L’invio dell’SMS non è disponibile.', 'Sending the SMS is not available.', 'Der SMS-Versand ist nicht verfügbar.', 'L’envoi du SMS n’est pas disponible.'],
            'signatures.error.storage_failed' => ['Il documento non può essere salvato: riprova.', 'The document could not be stored: try again.', 'Das Dokument konnte nicht gespeichert werden: Bitte erneut versuchen.', 'Le document n’a pas pu être enregistré : réessayez.'],
            'signatures.error.strong_authentication_required' => ['Questo livello richiede un metodo di identificazione forte (SMS, app del provider, SPID/CIE o SwissID).', 'This level requires a strong identification method (SMS, provider app, SPID/CIE or SwissID).', 'Diese Stufe erfordert eine starke Identifikationsmethode (SMS, Anbieter-App, SPID/CIE oder SwissID).', 'Ce niveau exige une méthode d’identification forte (SMS, application du fournisseur, SPID/CIE ou SwissID).'],
            'signatures.error.tenant_missing' => ['Nessuna azienda attiva.', 'No active company.', 'Kein aktives Unternehmen.', 'Aucune entreprise active.'],
            'signatures.error.too_many_recipients' => ['Troppi destinatari per una sola richiesta.', 'Too many recipients for one request.', 'Zu viele Empfänger für eine Anfrage.', 'Trop de destinataires pour une seule demande.'],
            'signatures.error.unsupported_pdf' => ['Questo PDF non può essere firmato (ad esempio è cifrato o danneggiato).', 'This PDF cannot be signed (for example it is encrypted or damaged).', 'Dieses PDF kann nicht signiert werden (zum Beispiel weil es verschlüsselt oder beschädigt ist).', 'Ce PDF ne peut pas être signé (par exemple parce qu’il est chiffré ou endommagé).'],
            'signatures.error.validation' => ['Controlla i dati inseriti.', 'Check the data you entered.', 'Prüfen Sie Ihre Eingaben.', 'Vérifiez les données saisies.'],
            'signatures.error.webhook_event_unknown' => ['Evento del provider non riconosciuto.', 'Provider event not recognised.', 'Ereignis des Anbieters nicht erkannt.', 'Événement du fournisseur non reconnu.'],
            'signatures.error.webhook_invalid' => ['Notifica del provider non valida.', 'Invalid provider notification.', 'Ungültige Benachrichtigung des Anbieters.', 'Notification du fournisseur invalide.'],
            'signatures.error.webhook_not_supported' => ['Questo provider non invia notifiche.', 'This provider does not send notifications.', 'Dieser Anbieter sendet keine Benachrichtigungen.', 'Ce fournisseur n’envoie pas de notifications.'],
            'signatures.error.webhook_replayed' => ['Notifica già elaborata.', 'Notification already processed.', 'Benachrichtigung bereits verarbeitet.', 'Notification déjà traitée.'],
            'signatures.error.webhook_stale' => ['Notifica troppo vecchia.', 'Notification too old.', 'Benachrichtigung zu alt.', 'Notification trop ancienne.'],
            'signatures.error.webhook_unauthenticated' => ['Notifica non autenticata.', 'Unauthenticated notification.', 'Nicht authentifizierte Benachrichtigung.', 'Notification non authentifiée.'],
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
