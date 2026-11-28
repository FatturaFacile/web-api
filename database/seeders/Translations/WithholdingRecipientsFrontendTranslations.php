<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the withholding taxes module (Mod 356): recipients of commissions, royalties, occasional work and amateur
 * sports fees (IT) and of Swiss artists and de minimis payments (CH): dashboard with threshold progress, profile and
 * declarations, compensation simulator, preventive INL communication, settlement center (tax register, posting, F24,
 * UniEmens, annual CU / AVS records), plus the codes the API reports (alerts, warnings, notes, errors).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, P.IVA, CF, UID, INPS, INL, F24, CU, UniEmens, IRPEF, Enasarco, FIRR, OAVS, LIFD) stay untranslated.
 */
final class WithholdingRecipientsFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.WITHHOLDING_RECIPIENTS' => ['Ritenute e percipienti', 'Withholding taxes and recipients', 'Quellensteuern und Empfänger', 'Retenues et bénéficiaires'],
            'NAV.WITHHOLDING_RECIPIENTS_DASHBOARD' => ['Cruscotto percipienti', 'Recipients dashboard', 'Empfänger-Dashboard', 'Tableau de bord des bénéficiaires'],
            'NAV.WITHHOLDING_RECIPIENTS_SIMULATOR' => ['Simulatore notule e compensi', 'Fee note and compensation simulator', 'Simulator für Honorarnoten und Vergütungen', 'Simulateur de notes d’honoraires et de rémunérations'],
            'NAV.WITHHOLDING_RECIPIENTS_SETTLEMENTS' => ['Liquidazioni ed export', 'Settlements and exports', 'Abrechnungen und Exporte', 'Règlements et exports'],
            'PAGE_TITLE.WITHHOLDING_RECIPIENTS_DASHBOARD' => ['Percipienti di provvigioni, diritti d’autore e compensi occasionali', 'Recipients of commissions, royalties and occasional fees', 'Empfänger von Provisionen, Urheberrechtsvergütungen und Gelegenheitshonoraren', 'Bénéficiaires de commissions, droits d’auteur et honoraires occasionnels'],
            'PAGE_TITLE.WITHHOLDING_RECIPIENTS_SIMULATOR' => ['Simulatore di notule e compensi con ritenute', 'Fee note and compensation simulator with withholdings', 'Simulator für Honorarnoten und Vergütungen mit Quellensteuern', 'Simulateur de notes d’honoraires et rémunérations avec retenues'],
            'PAGE_TITLE.WITHHOLDING_RECIPIENTS_SETTLEMENTS' => ['Liquidazione, F24, UniEmens e certificazioni annuali', 'Settlement, F24, UniEmens and annual certifications', 'Abrechnung, F24, UniEmens und Jahresbescheinigungen', 'Règlement, F24, UniEmens et attestations annuelles'],

            // --- Regimes, jurisdictions and enumerations ---
            'withholdingRecipients.regime.agent_25bis' => ['Agente di commercio (art. 25-bis)', 'Sales agent (art. 25-bis)', 'Handelsvertreter (Art. 25-bis)', 'Agent commercial (art. 25-bis)'],
            'withholdingRecipients.regime.author_royalty' => ['Diritti d’autore', 'Author royalties', 'Urheberrechtsvergütungen', 'Droits d’auteur'],
            'withholdingRecipients.regime.occasional_worker' => ['Lavoratore occasionale', 'Occasional worker', 'Gelegentlich Beschäftigter', 'Travailleur occasionnel'],
            'withholdingRecipients.regime.amateur_sports' => ['Collaboratore sportivo dilettantistico', 'Amateur sports collaborator', 'Mitarbeiter im Amateursport', 'Collaborateur sportif amateur'],
            'withholdingRecipients.regime.swiss_foreign_artist' => ['Artista, atleta o conferenziere estero (CH)', 'Foreign artist, athlete or speaker (CH)', 'Ausländischer Künstler, Sportler oder Referent (CH)', 'Artiste, sportif ou conférencier étranger (CH)'],
            'withholdingRecipients.regime.swiss_de_minimis' => ['Svizzera: soglia de minimis AVS', 'Switzerland: AVS de minimis threshold', 'Schweiz: AVS-Bagatellgrenze', 'Suisse : seuil de minimis AVS'],
            'withholdingRecipients.jurisdiction.IT' => ['Italia', 'Italy', 'Italien', 'Italie'],
            'withholdingRecipients.jurisdiction.CH' => ['Svizzera', 'Switzerland', 'Schweiz', 'Suisse'],
            'withholdingRecipients.activityType.artist' => ['Artista', 'Artist', 'Künstler', 'Artiste'],
            'withholdingRecipients.activityType.athlete' => ['Atleta', 'Athlete', 'Sportler', 'Sportif'],
            'withholdingRecipients.activityType.speaker' => ['Conferenziere', 'Speaker', 'Referent', 'Conférencier'],
            'withholdingRecipients.enasarcoMandate.mono' => ['Monomandatario', 'Single-mandate', 'Einfirmenvertreter', 'Monomandataire'],
            'withholdingRecipients.enasarcoMandate.pluri' => ['Plurimandatario', 'Multi-mandate', 'Mehrfirmenvertreter', 'Plurimandataire'],
            'withholdingRecipients.causal.A' => ['A - Lavoro autonomo professionale abituale', 'A - Habitual professional self-employment', 'A - Gewohnheitsmässige selbständige Berufstätigkeit', 'A - Travail indépendant professionnel habituel'],
            'withholdingRecipients.causal.M' => ['M - Lavoro autonomo occasionale', 'M - Occasional self-employment', 'M - Gelegentliche selbständige Tätigkeit', 'M - Travail indépendant occasionnel'],
            'withholdingRecipients.causal.M1' => ['M1 - Obblighi di fare, non fare o permettere', 'M1 - Obligations to do, not to do or to permit', 'M1 - Verpflichtungen zu Tun, Unterlassen oder Dulden', 'M1 - Obligations de faire, ne pas faire ou permettre'],
            'withholdingRecipients.causal.B' => ['B - Utilizzazione economica di opere dell’ingegno', 'B - Economic exploitation of intellectual works', 'B - Wirtschaftliche Verwertung geistiger Werke', 'B - Exploitation économique d’œuvres de l’esprit'],
            'withholdingRecipients.causal.N' => ['N - Compensi per attività sportive dilettantistiche', 'N - Fees for amateur sports activities', 'N - Entschädigungen für Amateursporttätigkeit', 'N - Rémunérations pour activités sportives amateurs'],
            'withholdingRecipients.causal.Q' => ['Q - Provvigioni ad agente monomandatario', 'Q - Commissions to a single-mandate agent', 'Q - Provisionen an Einfirmenvertreter', 'Q - Commissions à un agent monomandataire'],
            'withholdingRecipients.causal.R' => ['R - Provvigioni ad agente plurimandatario', 'R - Commissions to a multi-mandate agent', 'R - Provisionen an Mehrfirmenvertreter', 'R - Commissions à un agent plurimandataire'],
            'withholdingRecipients.causal.V' => ['V - Provvigioni ad altri intermediari', 'V - Commissions to other intermediaries', 'V - Provisionen an andere Vermittler', 'V - Commissions à d’autres intermédiaires'],
            'withholdingRecipients.certStatus.active' => ['Valida', 'Active', 'Gültig', 'Valide'],
            'withholdingRecipients.certStatus.expiring' => ['In scadenza', 'Expiring', 'Läuft bald ab', 'Bientôt expirée'],
            'withholdingRecipients.certStatus.expired' => ['Scaduta', 'Expired', 'Abgelaufen', 'Expirée'],
            'withholdingRecipients.certStatus.upcoming' => ['Non ancora valida', 'Not yet valid', 'Noch nicht gültig', 'Pas encore valide'],
            'withholdingRecipients.inlStatus.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'withholdingRecipients.inlStatus.sent' => ['Inviata', 'Sent', 'Gesendet', 'Envoyée'],
            'withholdingRecipients.inlStatus.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'withholdingRecipients.settlementStatus.posted' => ['Contabilizzato', 'Posted', 'Verbucht', 'Comptabilisé'],
            'withholdingRecipients.status.inactive' => ['Non attivo', 'Inactive', 'Inaktiv', 'Inactif'],

            // --- Alerts of the dashboard ---
            'withholdingRecipients.alert.agent_certificate_missing' => ['Manca la dichiarazione al 20%', 'Missing 20% declaration', 'Erklärung zu 20 % fehlt', 'Déclaration à 20 % manquante'],
            'withholdingRecipients.alert.agent_certificate_expiring' => ['Dichiarazione al 20% in scadenza', '20% declaration expiring', 'Erklärung zu 20 % läuft ab', 'Déclaration à 20 % bientôt expirée'],
            'withholdingRecipients.alert.enasarco_mandate_missing' => ['Mandato Enasarco mancante', 'Enasarco mandate missing', 'Enasarco-Mandat fehlt', 'Mandat Enasarco manquant'],
            'withholdingRecipients.alert.birth_date_missing' => ['Data di nascita mancante', 'Birth date missing', 'Geburtsdatum fehlt', 'Date de naissance manquante'],
            'withholdingRecipients.alert.inl_notification_missing' => ['Comunicazione INL mancante', 'INL communication missing', 'INL-Mitteilung fehlt', 'Communication INL manquante'],
            'withholdingRecipients.alert.occasional_threshold_reached' => ['Soglia INPS dei 5.000 euro raggiunta', 'INPS threshold of EUR 5,000 reached', 'INPS-Schwelle von 5’000 Euro erreicht', 'Seuil INPS de 5 000 euros atteint'],
            'withholdingRecipients.alert.sports_threshold_reached' => ['Soglia dei compensi sportivi raggiunta', 'Amateur sports fee threshold reached', 'Schwelle der Sportvergütungen erreicht', 'Seuil des rémunérations sportives atteint'],
            'withholdingRecipients.alert.avs_de_minimis_exceeded' => ['Soglia de minimis AVS superata', 'AVS de minimis threshold exceeded', 'AVS-Bagatellgrenze überschritten', 'Seuil de minimis AVS dépassé'],

            // --- Warnings of the calculation ---
            'withholdingRecipients.warning.enasarco_mandate_missing' => ['Il mandato Enasarco non è indicato: viene applicato il massimale più prudente.', 'The Enasarco mandate is not set: the most prudent ceiling is applied.', 'Das Enasarco-Mandat ist nicht angegeben: es gilt die vorsichtigste Obergrenze.', 'Le mandat Enasarco n’est pas indiqué : le plafond le plus prudent est appliqué.'],
            'withholdingRecipients.warning.enasarco_ceiling_reached' => ['Il massimale Enasarco è raggiunto: oltre il limite non si versano contributi.', 'The Enasarco ceiling is reached: no contributions are due above the limit.', 'Die Enasarco-Obergrenze ist erreicht: darüber sind keine Beiträge geschuldet.', 'Le plafond Enasarco est atteint : aucune cotisation n’est due au-delà de la limite.'],
            'withholdingRecipients.warning.birth_date_missing' => ['Data di nascita mancante: l’età non può essere verificata e si applica la deduzione ordinaria.', 'Birth date missing: the age cannot be checked and the standard deduction applies.', 'Geburtsdatum fehlt: das Alter kann nicht geprüft werden, es gilt der ordentliche Abzug.', 'Date de naissance manquante : l’âge ne peut pas être vérifié et la déduction ordinaire s’applique.'],
            'withholdingRecipients.warning.inps_threshold_exceeded' => ['Superata la soglia di 5.000 euro: i contributi INPS sono dovuti sull’eccedenza.', 'The EUR 5,000 threshold is exceeded: INPS contributions are due on the excess.', 'Die Schwelle von 5’000 Euro ist überschritten: auf den Mehrbetrag sind INPS-Beiträge geschuldet.', 'Le seuil de 5 000 euros est dépassé : les cotisations INPS sont dues sur l’excédent.'],
            'withholdingRecipients.warning.sports_irpef_threshold_exceeded' => ['Superata la soglia IRPEF di 15.000 euro: la quota eccedente è imponibile.', 'The IRPEF threshold of EUR 15,000 is exceeded: the excess is taxable.', 'Die IRPEF-Schwelle von 15’000 Euro ist überschritten: der Mehrbetrag ist steuerpflichtig.', 'Le seuil IRPEF de 15 000 euros est dépassé : l’excédent est imposable.'],
            'withholdingRecipients.warning.sports_inps_threshold_exceeded' => ['Superata la soglia di 5.000 euro: i contributi INPS sono dovuti sull’eccedenza.', 'The EUR 5,000 threshold is exceeded: INPS contributions are due on the excess.', 'Die Schwelle von 5’000 Euro ist überschritten: auf den Mehrbetrag sind INPS-Beiträge geschuldet.', 'Le seuil de 5 000 euros est dépassé : les cotisations INPS sont dues sur l’excédent.'],
            'withholdingRecipients.warning.avs_de_minimis_exceeded' => ['Superata la soglia de minimis di 2.300 franchi: i contributi AVS sono dovuti.', 'The de minimis threshold of CHF 2,300 is exceeded: AVS contributions are due.', 'Die Bagatellgrenze von 2’300 Franken ist überschritten: AVS-Beiträge sind geschuldet.', 'Le seuil de minimis de 2 300 francs est dépassé : les cotisations AVS sont dues.'],

            // --- Notes of the calculation ---
            'withholdingRecipients.note.agent_reduced_base_20' => ['Ritenuta sul 20% della provvigione (dichiarazione dell’agente con struttura propria).', 'Withholding on 20% of the commission (declaration of an agent with its own organisation).', 'Quellensteuer auf 20 % der Provision (Erklärung des Vertreters mit eigener Organisation).', 'Retenue sur 20 % de la commission (déclaration de l’agent avec organisation propre).'],
            'withholdingRecipients.note.agent_standard_base_50' => ['Ritenuta sul 50% della provvigione.', 'Withholding on 50% of the commission.', 'Quellensteuer auf 50 % der Provision.', 'Retenue sur 50 % de la commission.'],
            'withholdingRecipients.note.agent_certificate_missing' => ['Dichiarazione al 20% assente o non valida: si applica la base ordinaria del 50%.', '20% declaration missing or not valid: the standard 50% base applies.', 'Erklärung zu 20 % fehlt oder ist ungültig: es gilt die ordentliche Basis von 50 %.', 'Déclaration à 20 % absente ou non valide : la base ordinaire de 50 % s’applique.'],
            'withholdingRecipients.note.royalty_under_35' => ['Autore under 35: deduzione forfettaria del 40%.', 'Author under 35: 40% flat-rate deduction.', 'Urheber unter 35: pauschaler Abzug von 40 %.', 'Auteur de moins de 35 ans : déduction forfaitaire de 40 %.'],
            'withholdingRecipients.note.royalty_35_and_over' => ['Autore di 35 anni o più: deduzione forfettaria del 25%.', 'Author aged 35 or over: 25% flat-rate deduction.', 'Urheber ab 35 Jahren: pauschaler Abzug von 25 %.', 'Auteur de 35 ans ou plus : déduction forfaitaire de 25 %.'],
            'withholdingRecipients.note.out_of_vat_scope_art_3_c4_a' => ['Operazione fuori campo IVA (art. 3, comma 4, lettera a, D.P.R. 633/1972).', 'Transaction outside the scope of VAT (art. 3, para. 4, letter a, D.P.R. 633/1972).', 'Umsatz ausserhalb des Anwendungsbereichs der MwSt (Art. 3 Abs. 4 Bst. a, D.P.R. 633/1972).', 'Opération hors champ de la TVA (art. 3, al. 4, lettre a, D.P.R. 633/1972).'],
            'withholdingRecipients.note.revenue_stamp_applied' => ['Imposta di bollo di 2 euro applicata (importo oltre 77,47 euro).', 'EUR 2 revenue stamp applied (amount above EUR 77.47).', 'Stempelsteuer von 2 Euro angewendet (Betrag über 77,47 Euro).', 'Droit de timbre de 2 euros appliqué (montant supérieur à 77,47 euros).'],
            'withholdingRecipients.note.sports_inps_full_base' => ['Contributi INPS sull’intero imponibile.', 'INPS contributions on the full taxable amount.', 'INPS-Beiträge auf den gesamten steuerpflichtigen Betrag.', 'Cotisations INPS sur l’intégralité de la base imposable.'],
            'withholdingRecipients.note.sports_inps_half_base' => ['Contributi INPS sul 50% dell’imponibile (regime transitorio).', 'INPS contributions on 50% of the taxable amount (transitional regime).', 'INPS-Beiträge auf 50 % des steuerpflichtigen Betrags (Übergangsregelung).', 'Cotisations INPS sur 50 % de la base imposable (régime transitoire).'],
            'withholdingRecipients.note.avs_cultural_sector_from_first_franc' => ['Settore culturale: i contributi AVS sono dovuti dal primo franco.', 'Cultural sector: AVS contributions are due from the first franc.', 'Kultursektor: AVS-Beiträge sind ab dem ersten Franken geschuldet.', 'Secteur culturel : les cotisations AVS sont dues dès le premier franc.'],
            'withholdingRecipients.note.avs_de_minimis_exempt' => ['Sotto la soglia de minimis: nessun contributo AVS.', 'Below the de minimis threshold: no AVS contribution.', 'Unter der Bagatellgrenze: kein AVS-Beitrag.', 'Sous le seuil de minimis : aucune cotisation AVS.'],
            'withholdingRecipients.note.source_tax_documented_expenses' => ['Imposta alla fonte sul netto delle spese documentate.', 'Source tax on the amount net of documented expenses.', 'Quellensteuer auf dem Betrag nach Abzug der belegten Kosten.', 'Impôt à la source sur le montant net des frais justifiés.'],
            'withholdingRecipients.note.source_tax_flat_expenses' => ['Imposta alla fonte sul netto delle spese forfettarie.', 'Source tax on the amount net of flat-rate expenses.', 'Quellensteuer auf dem Betrag nach Abzug der Pauschalkosten.', 'Impôt à la source sur le montant net des frais forfaitaires.'],

            // --- Thresholds ---
            'withholdingRecipients.threshold.occasional_5000' => ['Soglia INPS lavoro occasionale (5.000 euro)', 'Occasional work INPS threshold (EUR 5,000)', 'INPS-Schwelle für Gelegenheitsarbeit (5’000 Euro)', 'Seuil INPS travail occasionnel (5 000 euros)'],
            'withholdingRecipients.threshold.sports_5000' => ['Soglia di esenzione compensi sportivi (5.000 euro)', 'Amateur sports exemption threshold (EUR 5,000)', 'Befreiungsschwelle Sportvergütungen (5’000 Euro)', 'Seuil d’exonération des rémunérations sportives (5 000 euros)'],
            'withholdingRecipients.threshold.sports_15000' => ['Soglia IRPEF compensi sportivi (15.000 euro)', 'IRPEF threshold for sports fees (EUR 15,000)', 'IRPEF-Schwelle Sportvergütungen (15’000 Euro)', 'Seuil IRPEF des rémunérations sportives (15 000 euros)'],
            'withholdingRecipients.threshold.avs_2300' => ['Soglia de minimis AVS (2.300 franchi)', 'AVS de minimis threshold (CHF 2,300)', 'AVS-Bagatellgrenze (2’300 Franken)', 'Seuil de minimis AVS (2 300 francs)'],
            'withholdingRecipients.threshold.enasarco_ceiling' => ['Massimale contributivo Enasarco', 'Enasarco contribution ceiling', 'Enasarco-Beitragsobergrenze', 'Plafond de cotisation Enasarco'],

            // --- Errors of the API ---
            'withholdingRecipients.error.inl_not_applicable' => ['La comunicazione INL è prevista solo per i lavoratori occasionali.', 'The INL communication applies only to occasional workers.', 'Die INL-Mitteilung gilt nur für gelegentlich Beschäftigte.', 'La communication INL ne concerne que les travailleurs occasionnels.'],
            'withholdingRecipients.error.inl_cancelled' => ['La comunicazione INL è stata annullata.', 'The INL communication has been cancelled.', 'Die INL-Mitteilung wurde storniert.', 'La communication INL a été annulée.'],
            'withholdingRecipients.error.inl_notification_missing' => ['Manca la comunicazione preventiva INL inviata prima dell’inizio della prestazione: il pagamento non può essere registrato.', 'The preventive INL communication sent before the work started is missing: the payment cannot be posted.', 'Die vor Arbeitsbeginn gesendete INL-Mitteilung fehlt: die Zahlung kann nicht verbucht werden.', 'La communication préventive INL envoyée avant le début de la prestation est manquante : le paiement ne peut pas être comptabilisé.'],
            'withholdingRecipients.error.inl_notification_late' => ['La comunicazione INL è stata inviata dopo l’inizio della prestazione: il pagamento non può essere registrato.', 'The INL communication was sent after the work started: the payment cannot be posted.', 'Die INL-Mitteilung wurde nach Arbeitsbeginn gesendet: die Zahlung kann nicht verbucht werden.', 'La communication INL a été envoyée après le début de la prestation : le paiement ne peut pas être comptabilisé.'],
            'withholdingRecipients.error.inl_notification_expired' => ['La comunicazione INL non copre più la data della prestazione: il pagamento non può essere registrato.', 'The INL communication no longer covers the date of the service: the payment cannot be posted.', 'Die INL-Mitteilung deckt das Datum der Leistung nicht mehr ab: die Zahlung kann nicht verbucht werden.', 'La communication INL ne couvre plus la date de la prestation : le paiement ne peut pas être comptabilisé.'],
            'withholdingRecipients.error.recipient_inactive' => ['Il percipiente non è attivo.', 'The recipient is not active.', 'Der Empfänger ist nicht aktiv.', 'Le bénéficiaire n’est pas actif.'],
            'withholdingRecipients.error.service_after_payment' => ['La data della prestazione non può essere successiva a quella del pagamento.', 'The service date cannot be after the payment date.', 'Das Leistungsdatum darf nicht nach dem Zahlungsdatum liegen.', 'La date de la prestation ne peut pas être postérieure à celle du paiement.'],
            'withholdingRecipients.error.net_not_positive' => ['Il netto da pagare non è positivo: ritenute e contributi superano il compenso.', 'The net payable is not positive: withholdings and contributions exceed the fee.', 'Der Nettobetrag ist nicht positiv: Abzüge und Beiträge übersteigen das Honorar.', 'Le net à payer n’est pas positif : les retenues et cotisations dépassent la rémunération.'],
            'withholdingRecipients.error.regime_locked' => ['Il regime non può essere modificato dopo la registrazione di compensi.', 'The regime cannot be changed once compensations have been posted.', 'Das Regime kann nach der Verbuchung von Vergütungen nicht mehr geändert werden.', 'Le régime ne peut plus être modifié une fois des rémunérations comptabilisées.'],
            'withholdingRecipients.error.recipient_has_settlements' => ['Il percipiente ha compensi registrati e non può essere eliminato.', 'The recipient has posted compensations and cannot be deleted.', 'Der Empfänger hat verbuchte Vergütungen und kann nicht gelöscht werden.', 'Le bénéficiaire a des rémunérations comptabilisées et ne peut pas être supprimé.'],
            'withholdingRecipients.error.certificate_not_applicable' => ['La dichiarazione al 20% è prevista solo per gli agenti.', 'The 20% declaration applies only to agents.', 'Die Erklärung zu 20 % gilt nur für Vertreter.', 'La déclaration à 20 % ne concerne que les agents.'],
            'withholdingRecipients.error.generic' => ['Operazione non riuscita.', 'The operation failed.', 'Der Vorgang ist fehlgeschlagen.', 'L’opération a échoué.'],

            // --- Messages ---
            'withholdingRecipients.message.saved' => ['Dati salvati.', 'Data saved.', 'Daten gespeichert.', 'Données enregistrées.'],
            'withholdingRecipients.message.deleted' => ['Elemento eliminato.', 'Item deleted.', 'Eintrag gelöscht.', 'Élément supprimé.'],
            'withholdingRecipients.message.posted' => ['Compenso registrato.', 'Compensation posted.', 'Vergütung verbucht.', 'Rémunération comptabilisée.'],
            'withholdingRecipients.message.batchPosted' => ['Compensi registrati.', 'Compensations posted.', 'Vergütungen verbucht.', 'Rémunérations comptabilisées.'],
            'withholdingRecipients.message.returnsGenerated' => ['Certificazioni annuali generate.', 'Annual returns generated.', 'Jahresbescheinigungen erstellt.', 'Attestations annuelles générées.'],
            'withholdingRecipients.message.inlGenerated' => ['Comunicazione INL generata.', 'INL communication generated.', 'INL-Mitteilung erstellt.', 'Communication INL générée.'],
            'withholdingRecipients.message.inlSent' => ['Comunicazione INL segnata come inviata.', 'INL communication marked as sent.', 'INL-Mitteilung als gesendet markiert.', 'Communication INL marquée comme envoyée.'],
            'withholdingRecipients.message.inlCancelled' => ['Comunicazione INL annullata.', 'INL communication cancelled.', 'INL-Mitteilung storniert.', 'Communication INL annulée.'],

            // --- Common actions ---
            'withholdingRecipients.action.all' => ['Tutti', 'All', 'Alle', 'Tous'],
            'withholdingRecipients.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'withholdingRecipients.action.close' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
            'withholdingRecipients.action.confirmDelete' => ['Eliminare l’elemento?', 'Delete this item?', 'Diesen Eintrag löschen?', 'Supprimer cet élément ?'],
            'withholdingRecipients.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'withholdingRecipients.action.new' => ['Nuovo percipiente', 'New recipient', 'Neuer Empfänger', 'Nouveau bénéficiaire'],
            'withholdingRecipients.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'withholdingRecipients.action.refresh' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'withholdingRecipients.action.reset' => ['Azzera', 'Reset', 'Zurücksetzen', 'Réinitialiser'],
            'withholdingRecipients.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],

            // --- Fields ---
            'withholdingRecipients.field.accrualMonth' => ['Mese di competenza', 'Accrual month', 'Abrechnungsmonat', 'Mois de rattachement'],
            'withholdingRecipients.field.acquisitionExpenses' => ['Spese di acquisizione documentate', 'Documented acquisition expenses', 'Belegte Gewinnungskosten', 'Frais d’acquisition justifiés'],
            'withholdingRecipients.field.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'withholdingRecipients.field.activityType' => ['Tipo di attività', 'Activity type', 'Art der Tätigkeit', 'Type d’activité'],
            'withholdingRecipients.field.avsNumber' => ['Numero AVS', 'AVS number', 'AVS-Nummer', 'Numéro AVS'],
            'withholdingRecipients.field.birthDate' => ['Data di nascita', 'Birth date', 'Geburtsdatum', 'Date de naissance'],
            'withholdingRecipients.field.canton' => ['Cantone (sigla)', 'Canton (code)', 'Kanton (Kürzel)', 'Canton (sigle)'],
            'withholdingRecipients.field.country' => ['Paese di residenza (sigla)', 'Country of residence (code)', 'Wohnsitzland (Kürzel)', 'Pays de résidence (sigle)'],
            'withholdingRecipients.field.cuCausal' => ['Causale CU', 'CU causal', 'CU-Kennziffer', 'Code CU'],
            'withholdingRecipients.field.culturalSector' => ['Settore culturale', 'Cultural sector', 'Kultursektor', 'Secteur culturel'],
            'withholdingRecipients.field.documentNumber' => ['Numero documento', 'Document number', 'Dokumentnummer', 'Numéro de document'],
            'withholdingRecipients.field.enasarcoMandate' => ['Mandato Enasarco', 'Enasarco mandate', 'Enasarco-Mandat', 'Mandat Enasarco'],
            'withholdingRecipients.field.firrEnabled' => ['Accantonamento FIRR', 'FIRR accrual', 'FIRR-Rückstellung', 'Provision FIRR'],
            'withholdingRecipients.field.fiscalYear' => ['Anno fiscale', 'Fiscal year', 'Steuerjahr', 'Exercice fiscal'],
            'withholdingRecipients.field.grossAmount' => ['Compenso lordo', 'Gross amount', 'Bruttobetrag', 'Montant brut'],
            'withholdingRecipients.field.jurisdiction' => ['Giurisdizione', 'Jurisdiction', 'Rechtsraum', 'Juridiction'],
            'withholdingRecipients.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'withholdingRecipients.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'withholdingRecipients.field.onlyAlerts' => ['Solo con segnalazioni', 'Only with alerts', 'Nur mit Hinweisen', 'Seulement avec alertes'],
            'withholdingRecipients.field.paymentDate' => ['Data di pagamento', 'Payment date', 'Zahlungsdatum', 'Date de paiement'],
            'withholdingRecipients.field.paymentReference' => ['Riferimento del pagamento', 'Payment reference', 'Zahlungsreferenz', 'Référence du paiement'],
            'withholdingRecipients.field.performanceDays' => ['Giorni di prestazione', 'Performance days', 'Auftrittstage', 'Jours de prestation'],
            'withholdingRecipients.field.priorAvsBase' => ['Base AVS già corrisposta nell’anno', 'AVS base already paid in the year', 'Im Jahr bereits ausbezahlte AVS-Basis', 'Base AVS déjà versée dans l’année'],
            'withholdingRecipients.field.priorCompanyIncome' => ['Compensi già pagati dall’azienda nell’anno', 'Amounts already paid by the company in the year', 'Im Jahr bereits vom Unternehmen bezahlte Beträge', 'Montants déjà versés par l’entreprise dans l’année'],
            'withholdingRecipients.field.recipient' => ['Percipiente', 'Recipient', 'Empfänger', 'Bénéficiaire'],
            'withholdingRecipients.field.reducedCertificate' => ['Dichiarazione al 20% valida', 'Valid 20% declaration', 'Gültige Erklärung zu 20 %', 'Déclaration à 20 % valide'],
            'withholdingRecipients.field.regime' => ['Regime', 'Regime', 'Regime', 'Régime'],
            'withholdingRecipients.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'withholdingRecipients.field.serviceDate' => ['Data della prestazione', 'Service date', 'Leistungsdatum', 'Date de la prestation'],
            'withholdingRecipients.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'withholdingRecipients.field.taxCode' => ['Codice fiscale (CF)', 'Tax code (CF)', 'Steuernummer (CF)', 'Code fiscal (CF)'],
            'withholdingRecipients.field.taxYear' => ['Anno d’imposta', 'Tax year', 'Steuerjahr', 'Année fiscale'],
            'withholdingRecipients.field.thirdPartyDeclaredOn' => ['Dichiarato il', 'Declared on', 'Erklärt am', 'Déclaré le'],
            'withholdingRecipients.field.thirdPartyIncome' => ['Redditi da terzi dichiarati', 'Declared income from third parties', 'Erklärte Einkünfte von Dritten', 'Revenus déclarés de tiers'],
            'withholdingRecipients.field.thirdPartyYear' => ['Anno dei redditi dichiarati', 'Year of the declared income', 'Jahr der erklärten Einkünfte', 'Année des revenus déclarés'],
            'withholdingRecipients.field.validFrom' => ['Valida dal', 'Valid from', 'Gültig ab', 'Valide à partir du'],
            'withholdingRecipients.field.validTo' => ['Valida fino al', 'Valid until', 'Gültig bis', 'Valide jusqu’au'],
            'withholdingRecipients.field.vatNumber' => ['Partita IVA (P.IVA)', 'VAT number (P.IVA)', 'MwSt-Nummer (P.IVA)', 'Numéro de TVA (P.IVA)'],
            'withholdingRecipients.field.year' => ['Anno', 'Year', 'Jahr', 'Année'],

            // --- Dashboard ---
            'withholdingRecipients.dashboard.empty' => ['Nessun percipiente corrisponde ai filtri.', 'No recipient matches the filters.', 'Kein Empfänger entspricht den Filtern.', 'Aucun bénéficiaire ne correspond aux filtres.'],
            'withholdingRecipients.column.alerts' => ['Segnalazioni', 'Alerts', 'Hinweise', 'Alertes'],
            'withholdingRecipients.column.payments' => ['Pagamenti', 'Payments', 'Zahlungen', 'Paiements'],
            'withholdingRecipients.column.thresholds' => ['Soglie', 'Thresholds', 'Schwellen', 'Seuils'],
            'withholdingRecipients.column.ytdGross' => ['Lordo da inizio anno', 'Gross year to date', 'Brutto seit Jahresbeginn', 'Brut depuis le début de l’année'],
            'withholdingRecipients.column.ytdWithheld' => ['Trattenuto da inizio anno', 'Withheld year to date', 'Einbehalten seit Jahresbeginn', 'Retenu depuis le début de l’année'],
            'withholdingRecipients.summary.recipients' => ['Percipienti', 'Recipients', 'Empfänger', 'Bénéficiaires'],
            'withholdingRecipients.summary.withAlerts' => ['Con segnalazioni', 'With alerts', 'Mit Hinweisen', 'Avec alertes'],
            'withholdingRecipients.summary.grossEur' => ['Lordo da inizio anno (EUR)', 'Gross year to date (EUR)', 'Brutto seit Jahresbeginn (EUR)', 'Brut depuis le début de l’année (EUR)'],
            'withholdingRecipients.summary.grossChf' => ['Lordo da inizio anno (CHF)', 'Gross year to date (CHF)', 'Brutto seit Jahresbeginn (CHF)', 'Brut depuis le début de l’année (CHF)'],
            'withholdingRecipients.summary.pendingInl' => ['Comunicazioni INL da inviare', 'INL communications to send', 'Zu sendende INL-Mitteilungen', 'Communications INL à envoyer'],

            // --- Profile drawer ---
            'withholdingRecipients.tab.profile' => ['Anagrafica', 'Profile', 'Stammdaten', 'Fiche'],
            'withholdingRecipients.tab.certificates' => ['Dichiarazioni al 20%', '20% declarations', 'Erklärungen zu 20 %', 'Déclarations à 20 %'],
            'withholdingRecipients.tab.inl' => ['Comunicazioni INL', 'INL communications', 'INL-Mitteilungen', 'Communications INL'],
            'withholdingRecipients.recipient.formTitleNew' => ['Nuovo percipiente', 'New recipient', 'Neuer Empfänger', 'Nouveau bénéficiaire'],
            'withholdingRecipients.recipient.formTitleEdit' => ['Profilo del percipiente', 'Recipient profile', 'Empfängerprofil', 'Profil du bénéficiaire'],
            'withholdingRecipients.recipient.thresholds' => ['Avanzamento delle soglie', 'Threshold progress', 'Stand der Schwellen', 'Avancement des seuils'],
            'withholdingRecipients.recipient.causalDefault' => ['Causale proposta dal regime', 'Causal proposed by the regime', 'Vom Regime vorgeschlagene Kennziffer', 'Code proposé par le régime'],
            'withholdingRecipients.hint.birthFromFiscalCode' => ['Data di nascita ricavata dal codice fiscale: {date}.', 'Birth date read from the tax code: {date}.', 'Aus der Steuernummer ermitteltes Geburtsdatum: {date}.', 'Date de naissance déduite du code fiscal : {date}.'],
            'withholdingRecipients.hint.useBirthDate' => ['Usa questa data', 'Use this date', 'Dieses Datum verwenden', 'Utiliser cette date'],
            'withholdingRecipients.hint.thirdPartyIncome' => ['Redditi autocertificati dal percipiente per prestazioni occasionali ricevute da altri committenti nell’anno: servono a verificare la soglia INPS.', 'Income the recipient self-certified for occasional work received from other clients in the year: it is used to check the INPS threshold.', 'Vom Empfänger selbst bescheinigte Einkünfte aus Gelegenheitsarbeit bei anderen Auftraggebern im Jahr: sie dienen der Prüfung der INPS-Schwelle.', 'Revenus auto-certifiés par le bénéficiaire pour des prestations occasionnelles reçues d’autres clients dans l’année : ils servent à vérifier le seuil INPS.'],

            // --- 20% declarations ---
            'withholdingRecipients.certificates.hint' => ['Ogni anno l’agente può dichiarare via PEC di avvalersi in modo continuativo di dipendenti o terzi: la ritenuta si applica sul 20% invece che sul 50% della provvigione. La dichiarazione deve arrivare entro il 31 dicembre dell’anno precedente.', 'Every year the agent can declare by PEC that it continuously uses employees or third parties: the withholding applies to 20% instead of 50% of the commission. The declaration must arrive by 31 December of the previous year.', 'Der Vertreter kann jährlich per PEC erklären, dass er laufend Angestellte oder Dritte einsetzt: die Quellensteuer wird auf 20 % statt 50 % der Provision erhoben. Die Erklärung muss bis zum 31. Dezember des Vorjahres eingehen.', 'Chaque année l’agent peut déclarer par PEC qu’il recourt de façon continue à des employés ou à des tiers : la retenue s’applique à 20 % au lieu de 50 % de la commission. La déclaration doit arriver avant le 31 décembre de l’année précédente.'],
            'withholdingRecipients.certificates.validity' => ['Validità', 'Validity', 'Gültigkeit', 'Validité'],
            'withholdingRecipients.certificates.receivedOn' => ['Ricevuta il', 'Received on', 'Eingegangen am', 'Reçue le'],
            'withholdingRecipients.certificates.late' => ['Ricevuta in ritardo', 'Received late', 'Verspätet eingegangen', 'Reçue en retard'],
            'withholdingRecipients.certificates.pec' => ['Riferimento PEC', 'PEC reference', 'PEC-Referenz', 'Référence PEC'],
            'withholdingRecipients.certificates.pecMessageId' => ['ID messaggio PEC', 'PEC message ID', 'PEC-Nachrichten-ID', 'ID du message PEC'],
            'withholdingRecipients.certificates.pecArchive' => ['Riferimento di archivio PEC', 'PEC archive reference', 'PEC-Archivreferenz', 'Référence d’archive PEC'],
            'withholdingRecipients.certificates.new' => ['Registra una nuova dichiarazione', 'Record a new declaration', 'Neue Erklärung erfassen', 'Enregistrer une nouvelle déclaration'],
            'withholdingRecipients.certificates.add' => ['Registra dichiarazione', 'Record declaration', 'Erklärung erfassen', 'Enregistrer la déclaration'],

            // --- INL communications ---
            'withholdingRecipients.inl.hint' => ['Il committente deve comunicare all’Ispettorato nazionale del lavoro (INL), prima dell’inizio della prestazione, ogni lavoratore autonomo occasionale. Senza una comunicazione inviata in tempo il pagamento non può essere registrato.', 'The client must notify the National Labour Inspectorate (INL) of every occasional self-employed worker before the work starts. Without a communication sent in time the payment cannot be posted.', 'Der Auftraggeber muss jeden gelegentlich selbständig Tätigen vor Arbeitsbeginn dem nationalen Arbeitsinspektorat (INL) melden. Ohne rechtzeitig gesendete Mitteilung kann die Zahlung nicht verbucht werden.', 'Le client doit déclarer chaque travailleur indépendant occasionnel à l’Inspection nationale du travail (INL) avant le début de la prestation. Sans communication envoyée à temps, le paiement ne peut pas être comptabilisé.'],
            'withholdingRecipients.inl.period' => ['Periodo della prestazione', 'Work period', 'Zeitraum der Leistung', 'Période de la prestation'],
            'withholdingRecipients.inl.activity' => ['Attività svolta', 'Activity', 'Tätigkeit', 'Activité'],
            'withholdingRecipients.inl.estimated' => ['Compenso stimato', 'Estimated compensation', 'Geschätzte Vergütung', 'Rémunération estimée'],
            'withholdingRecipients.inl.place' => ['Luogo della prestazione', 'Work place', 'Ort der Leistung', 'Lieu de la prestation'],
            'withholdingRecipients.inl.workStart' => ['Inizio della prestazione', 'Work start', 'Beginn der Leistung', 'Début de la prestation'],
            'withholdingRecipients.inl.workEnd' => ['Fine della prestazione', 'Work end', 'Ende der Leistung', 'Fin de la prestation'],
            'withholdingRecipients.inl.generate' => ['Genera comunicazione', 'Generate communication', 'Mitteilung erstellen', 'Générer la communication'],
            'withholdingRecipients.inl.markSent' => ['Segna come inviata', 'Mark as sent', 'Als gesendet markieren', 'Marquer comme envoyée'],
            'withholdingRecipients.inl.protocol' => ['Numero di protocollo', 'Protocol reference', 'Protokollnummer', 'Numéro de protocole'],
            'withholdingRecipients.inl.sentAt' => ['Data di invio', 'Sent on', 'Gesendet am', 'Envoyée le'],
            'withholdingRecipients.inl.sentInTime' => ['Inviata in tempo', 'Sent in time', 'Rechtzeitig gesendet', 'Envoyée à temps'],
            'withholdingRecipients.inl.sentLate' => ['Inviata dopo l’inizio', 'Sent after the start', 'Nach Beginn gesendet', 'Envoyée après le début'],
            'withholdingRecipients.inl.cancel' => ['Annulla comunicazione', 'Cancel communication', 'Mitteilung stornieren', 'Annuler la communication'],
            'withholdingRecipients.inl.confirmCancel' => ['Annullare la comunicazione INL?', 'Cancel the INL communication?', 'Die INL-Mitteilung stornieren?', 'Annuler la communication INL ?'],
            'withholdingRecipients.inl.format.pdf' => ['PDF', 'PDF', 'PDF', 'PDF'],
            'withholdingRecipients.inl.format.xml' => ['XML', 'XML', 'XML', 'XML'],
            'withholdingRecipients.inl.format.json' => ['JSON', 'JSON', 'JSON', 'JSON'],
            'withholdingRecipients.inl.gateCovered' => ['Una comunicazione INL inviata in tempo copre la data della prestazione.', 'An INL communication sent in time covers the service date.', 'Eine rechtzeitig gesendete INL-Mitteilung deckt das Leistungsdatum ab.', 'Une communication INL envoyée à temps couvre la date de la prestation.'],
            'withholdingRecipients.inl.gateBlocked' => ['Registrazione bloccata: manca una comunicazione INL valida.', 'Posting blocked: a valid INL communication is missing.', 'Verbuchung gesperrt: es fehlt eine gültige INL-Mitteilung.', 'Comptabilisation bloquée : une communication INL valide est manquante.'],

            // --- Simulator ---
            'withholdingRecipients.simulator.facts' => ['Dati del compenso', 'Compensation facts', 'Angaben zur Vergütung', 'Données de la rémunération'],
            'withholdingRecipients.simulator.result' => ['Risultato del calcolo', 'Calculation result', 'Berechnungsergebnis', 'Résultat du calcul'],
            'withholdingRecipients.simulator.recipient' => ['Percipiente registrato', 'Registered recipient', 'Registrierter Empfänger', 'Bénéficiaire enregistré'],
            'withholdingRecipients.simulator.adHoc' => ['Simulazione con dati liberi', 'Simulation with free facts', 'Simulation mit freien Angaben', 'Simulation avec données libres'],
            'withholdingRecipients.simulator.adHocFacts' => ['Dati del percipiente', 'Recipient facts', 'Angaben zum Empfänger', 'Données du bénéficiaire'],
            'withholdingRecipients.simulator.empty' => ['Inserisci un importo lordo per vedere il calcolo.', 'Enter a gross amount to see the calculation.', 'Geben Sie einen Bruttobetrag ein, um die Berechnung zu sehen.', 'Saisissez un montant brut pour voir le calcul.'],
            'withholdingRecipients.result.gross' => ['Compenso lordo', 'Gross amount', 'Bruttobetrag', 'Montant brut'],
            'withholdingRecipients.result.flatRate' => ['Deduzione forfettaria ({percent}%)', 'Flat-rate deduction ({percent}%)', 'Pauschalabzug ({percent} %)', 'Déduction forfaitaire ({percent} %)'],
            'withholdingRecipients.result.taxableBase' => ['Base imponibile', 'Taxable base', 'Steuerbare Basis', 'Base imposable'],
            'withholdingRecipients.result.irpefWithholding' => ['Ritenuta IRPEF', 'IRPEF withholding', 'IRPEF-Quellensteuer', 'Retenue IRPEF'],
            'withholdingRecipients.result.effectiveRate' => ['Aliquota effettiva', 'Effective rate', 'Effektiver Satz', 'Taux effectif'],
            'withholdingRecipients.result.stampDuty' => ['Imposta di bollo', 'Revenue stamp', 'Stempelsteuer', 'Droit de timbre'],
            'withholdingRecipients.result.inpsTotal' => ['Contributi INPS (totale)', 'INPS contributions (total)', 'INPS-Beiträge (gesamt)', 'Cotisations INPS (total)'],
            'withholdingRecipients.result.inpsBase' => ['Base contributiva', 'Contribution base', 'Beitragsbasis', 'Base de cotisation'],
            'withholdingRecipients.result.inpsWorker' => ['a carico del lavoratore (1/3)', 'paid by the worker (1/3)', 'zulasten des Arbeitnehmers (1/3)', 'à la charge du travailleur (1/3)'],
            'withholdingRecipients.result.inpsCompany' => ['a carico dell’azienda (2/3)', 'paid by the company (2/3)', 'zulasten des Unternehmens (2/3)', 'à la charge de l’entreprise (2/3)'],
            'withholdingRecipients.result.enasarcoAgent' => ['Enasarco a carico dell’agente', 'Enasarco paid by the agent', 'Enasarco zulasten des Vertreters', 'Enasarco à la charge de l’agent'],
            'withholdingRecipients.result.enasarcoCompany' => ['Enasarco a carico della ditta', 'Enasarco paid by the company', 'Enasarco zulasten des Unternehmens', 'Enasarco à la charge de l’entreprise'],
            'withholdingRecipients.result.enasarcoBase' => ['Base Enasarco', 'Enasarco base', 'Enasarco-Basis', 'Base Enasarco'],
            'withholdingRecipients.result.firr' => ['Accantonamento FIRR', 'FIRR accrual', 'FIRR-Rückstellung', 'Provision FIRR'],
            'withholdingRecipients.result.avsEmployee' => ['AVS a carico del percipiente', 'AVS paid by the recipient', 'AVS zulasten des Empfängers', 'AVS à la charge du bénéficiaire'],
            'withholdingRecipients.result.avsEmployer' => ['AVS a carico del datore di lavoro', 'AVS paid by the employer', 'AVS zulasten des Arbeitgebers', 'AVS à la charge de l’employeur'],
            'withholdingRecipients.result.avsBase' => ['Base AVS', 'AVS base', 'AVS-Basis', 'Base AVS'],
            'withholdingRecipients.result.acquisitionExpenses' => ['Spese di acquisizione', 'Acquisition expenses', 'Gewinnungskosten', 'Frais d’acquisition'],
            'withholdingRecipients.result.sourceTax' => ['Imposta alla fonte', 'Source tax', 'Quellensteuer', 'Impôt à la source'],
            'withholdingRecipients.result.nonTaxable' => ['Importo non imponibile', 'Non-taxable amount', 'Steuerfreier Betrag', 'Montant non imposable'],
            'withholdingRecipients.result.nonTaxableCode' => ['Codice CU', 'CU code', 'CU-Code', 'Code CU'],
            'withholdingRecipients.result.netPayable' => ['Netto da pagare', 'Net payable', 'Nettobetrag', 'Net à payer'],
            'withholdingRecipients.result.companyCost' => ['Costo per l’azienda', 'Cost for the company', 'Kosten für das Unternehmen', 'Coût pour l’entreprise'],
            'withholdingRecipients.result.thresholds' => ['Avanzamento delle soglie', 'Threshold progress', 'Stand der Schwellen', 'Avancement des seuils'],

            // --- Settlement center ---
            'withholdingRecipients.tab.register' => ['Registro delle ritenute', 'Tax register', 'Steuerregister', 'Registre des retenues'],
            'withholdingRecipients.tab.post' => ['Registra pagamento', 'Post payment', 'Zahlung verbuchen', 'Comptabiliser un paiement'],
            'withholdingRecipients.tab.batch' => ['Registrazione massiva', 'Batch posting', 'Sammelverbuchung', 'Comptabilisation en lot'],
            'withholdingRecipients.tab.flows' => ['F24 e UniEmens', 'F24 and UniEmens', 'F24 und UniEmens', 'F24 et UniEmens'],
            'withholdingRecipients.tab.annual' => ['CU e AVS annuali', 'Annual CU and AVS', 'Jährliche CU und AVS', 'CU et AVS annuels'],
            'withholdingRecipients.settle.readOnly' => ['Registrazione dei pagamenti, F24, UniEmens e certificazioni annuali sono riservati a titolari, amministratori e contabili: qui puoi consultare il registro.', 'Posting payments, F24, UniEmens and annual certifications are reserved to owners, administrators and accountants: here you can read the register.', 'Die Verbuchung von Zahlungen sowie F24, UniEmens und Jahresbescheinigungen sind Inhabern, Administratoren und Buchhaltern vorbehalten: hier können Sie das Register einsehen.', 'La comptabilisation des paiements, le F24, UniEmens et les attestations annuelles sont réservés aux propriétaires, administrateurs et comptables : vous pouvez ici consulter le registre.'],
            'withholdingRecipients.register.withheld' => ['Ritenuta / imposta alla fonte', 'Withholding / source tax', 'Quellensteuer', 'Retenue / impôt à la source'],
            'withholdingRecipients.register.f24Due' => ['Scadenza F24', 'F24 due date', 'F24-Fälligkeit', 'Échéance F24'],
            'withholdingRecipients.register.empty' => ['Nessun compenso registrato per i filtri scelti.', 'No compensation posted for the chosen filters.', 'Keine verbuchte Vergütung für die gewählten Filter.', 'Aucune rémunération comptabilisée pour les filtres choisis.'],

            'withholdingRecipients.post.hint' => ['Il pagamento viene registrato per cassa alla data indicata: calcola ritenute e contributi, prepara le righe F24 e alimenta la CU.', 'The payment is posted on a cash basis on the given date: it computes withholdings and contributions, prepares the F24 lines and feeds the CU.', 'Die Zahlung wird nach Zahlungseingang am angegebenen Datum verbucht: sie berechnet Quellensteuern und Beiträge, bereitet die F24-Zeilen vor und speist die CU.', 'Le paiement est comptabilisé selon la méthode de caisse à la date indiquée : il calcule retenues et cotisations, prépare les lignes F24 et alimente la CU.'],
            'withholdingRecipients.post.preview' => ['Anteprima del calcolo', 'Calculation preview', 'Vorschau der Berechnung', 'Aperçu du calcul'],
            'withholdingRecipients.post.previewEmpty' => ['Scegli il percipiente e inserisci l’importo per vedere l’anteprima.', 'Pick the recipient and enter the amount to see the preview.', 'Wählen Sie den Empfänger und geben Sie den Betrag ein, um die Vorschau zu sehen.', 'Choisissez le bénéficiaire et saisissez le montant pour voir l’aperçu.'],
            'withholdingRecipients.post.submit' => ['Registra pagamento', 'Post payment', 'Zahlung verbuchen', 'Comptabiliser le paiement'],
            'withholdingRecipients.post.confirm' => ['Registrare il pagamento? Le ritenute e le righe F24 vengono create.', 'Post the payment? The withholdings and F24 lines are created.', 'Die Zahlung verbuchen? Quellensteuern und F24-Zeilen werden erstellt.', 'Comptabiliser le paiement ? Les retenues et les lignes F24 sont créées.'],

            'withholdingRecipients.batch.hint' => ['Fino a {max} pagamenti in una sola operazione: o vengono registrati tutti o nessuno.', 'Up to {max} payments in a single operation: either all of them are posted or none.', 'Bis zu {max} Zahlungen in einem Vorgang: entweder werden alle oder keine verbucht.', 'Jusqu’à {max} paiements en une seule opération : soit tous sont comptabilisés, soit aucun.'],
            'withholdingRecipients.batch.addRow' => ['Aggiungi riga', 'Add row', 'Zeile hinzufügen', 'Ajouter une ligne'],
            'withholdingRecipients.batch.preview' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'withholdingRecipients.batch.result' => ['Esito', 'Result', 'Ergebnis', 'Résultat'],
            'withholdingRecipients.batch.inlBlocked' => ['Comunicazione INL mancante', 'INL communication missing', 'INL-Mitteilung fehlt', 'Communication INL manquante'],
            'withholdingRecipients.batch.submit' => ['Registra tutti i pagamenti', 'Post all payments', 'Alle Zahlungen verbuchen', 'Comptabiliser tous les paiements'],

            'withholdingRecipients.flows.hint' => ['Le righe F24 raggruppano le ritenute del mese per sezione e codice tributo: 1040 lavoro autonomo, diritti d’autore, prestazioni occasionali e compensi sportivi; 1038 provvigioni degli agenti; C10 contributi INPS Gestione Separata.', 'The F24 lines group the withholdings of the month by section and tax code: 1040 self-employment, royalties, occasional work and sports fees; 1038 agent commissions; C10 INPS Gestione Separata contributions.', 'Die F24-Zeilen gruppieren die Quellensteuern des Monats nach Abschnitt und Steuercode: 1040 selbständige Arbeit, Urheberrechte, Gelegenheitsarbeit und Sportvergütungen; 1038 Vertreterprovisionen; C10 INPS-Beiträge der Gestione Separata.', 'Les lignes F24 regroupent les retenues du mois par section et code tributaire : 1040 travail indépendant, droits d’auteur, prestations occasionnelles et rémunérations sportives ; 1038 commissions des agents ; C10 cotisations INPS Gestione Separata.'],
            'withholdingRecipients.flows.preview' => ['Mostra F24', 'Show F24', 'F24 anzeigen', 'Afficher le F24'],
            'withholdingRecipients.flows.exportF24' => ['Esporta F24 (CSV)', 'Export F24 (CSV)', 'F24 exportieren (CSV)', 'Exporter le F24 (CSV)'],
            'withholdingRecipients.flows.exportUniEmens' => ['Scarica UniEmens (XML)', 'Download UniEmens (XML)', 'UniEmens herunterladen (XML)', 'Télécharger UniEmens (XML)'],
            'withholdingRecipients.flows.dueDate' => ['Scadenza di versamento', 'Payment due date', 'Zahlungsfälligkeit', 'Échéance de paiement'],
            'withholdingRecipients.flows.taxCode' => ['Codice tributo e periodo', 'Tax code and period', 'Steuercode und Periode', 'Code tributaire et période'],
            'withholdingRecipients.flows.settlements' => ['Pagamenti', 'Payments', 'Zahlungen', 'Paiements'],
            'withholdingRecipients.flows.amount' => ['Importo a debito', 'Debit amount', 'Schuldbetrag', 'Montant dû'],
            'withholdingRecipients.flows.total' => ['Totale da versare', 'Total to pay', 'Total zu bezahlen', 'Total à verser'],
            'withholdingRecipients.flows.empty' => ['Nessuna riga F24 per il mese scelto.', 'No F24 line for the chosen month.', 'Keine F24-Zeile für den gewählten Monat.', 'Aucune ligne F24 pour le mois choisi.'],
            'withholdingRecipients.f24.section.erario' => ['Sezione Erario', 'Tax authority section', 'Abschnitt Steuerbehörde', 'Section Fisc'],
            'withholdingRecipients.f24.section.inps' => ['Sezione INPS', 'INPS section', 'Abschnitt INPS', 'Section INPS'],
            'withholdingRecipients.f24.code.1040' => ['Ritenute su lavoro autonomo, diritti d’autore, prestazioni occasionali e compensi sportivi', 'Withholdings on self-employment, royalties, occasional work and sports fees', 'Quellensteuern auf selbständige Arbeit, Urheberrechte, Gelegenheitsarbeit und Sportvergütungen', 'Retenues sur travail indépendant, droits d’auteur, prestations occasionnelles et rémunérations sportives'],
            'withholdingRecipients.f24.code.1038' => ['Ritenute sulle provvigioni degli agenti', 'Withholdings on agent commissions', 'Quellensteuern auf Vertreterprovisionen', 'Retenues sur les commissions des agents'],
            'withholdingRecipients.f24.code.C10' => ['Contributi INPS Gestione Separata', 'INPS Gestione Separata contributions', 'INPS-Beiträge der Gestione Separata', 'Cotisations INPS Gestione Separata'],

            'withholdingRecipients.annual.hint' => ['Genera le certificazioni dell’anno dai compensi registrati: CU per i percipienti italiani, registro AVS e imposta alla fonte per quelli svizzeri.', 'Generates the certifications of the year from the posted compensations: CU for Italian recipients, AVS and source tax records for Swiss ones.', 'Erstellt die Bescheinigungen des Jahres aus den verbuchten Vergütungen: CU für italienische Empfänger, AVS- und Quellensteuerregister für Schweizer.', 'Génère les attestations de l’année à partir des rémunérations comptabilisées : CU pour les bénéficiaires italiens, registre AVS et impôt à la source pour les suisses.'],
            'withholdingRecipients.annual.generate' => ['Genera certificazioni', 'Generate returns', 'Bescheinigungen erstellen', 'Générer les attestations'],
            'withholdingRecipients.annual.confirmGenerate' => ['Generare le certificazioni dell’anno? I dati già generati vengono aggiornati.', 'Generate the returns of the year? Records already generated are updated.', 'Die Bescheinigungen des Jahres erstellen? Bereits erstellte Daten werden aktualisiert.', 'Générer les attestations de l’année ? Les données déjà générées sont mises à jour.'],
            'withholdingRecipients.annual.export' => ['Esporta CSV', 'Export CSV', 'CSV exportieren', 'Exporter en CSV'],
            'withholdingRecipients.annual.type' => ['Tipo', 'Type', 'Typ', 'Type'],
            'withholdingRecipients.annual.returnType.CU' => ['CU', 'CU', 'CU', 'CU'],
            'withholdingRecipients.annual.returnType.AVS_QST' => ['AVS e imposta alla fonte', 'AVS and source tax', 'AVS und Quellensteuer', 'AVS et impôt à la source'],
            'withholdingRecipients.annual.gross' => ['Lordo', 'Gross', 'Brutto', 'Brut'],
            'withholdingRecipients.annual.nonTaxable' => ['Non imponibile', 'Non-taxable', 'Steuerfrei', 'Non imposable'],
            'withholdingRecipients.annual.withholding' => ['Ritenute', 'Withholdings', 'Quellensteuern', 'Retenues'],
            'withholdingRecipients.annual.inps' => ['INPS (lavoratore / azienda)', 'INPS (worker / company)', 'INPS (Arbeitnehmer / Unternehmen)', 'INPS (travailleur / entreprise)'],
            'withholdingRecipients.annual.enasarco' => ['Enasarco', 'Enasarco', 'Enasarco', 'Enasarco'],
            'withholdingRecipients.annual.avs' => ['AVS', 'AVS', 'AVS', 'AVS'],
            'withholdingRecipients.annual.sourceTax' => ['Imposta alla fonte', 'Source tax', 'Quellensteuer', 'Impôt à la source'],
            'withholdingRecipients.annual.empty' => ['Nessuna certificazione generata per l’anno scelto.', 'No return generated for the chosen year.', 'Keine Bescheinigung für das gewählte Jahr erstellt.', 'Aucune attestation générée pour l’année choisie.'],
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
