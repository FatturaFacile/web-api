<?php

declare(strict_types=1);

namespace Modules\HumanResources\Support;

use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\RecruitingLocale;

/**
 * Built-in localised texts of the recruiting module (it, de, fr, en), used whenever a tenant has not customised
 * them: stage labels, stage email templates, offer letter and public confirmation messages.
 */
final class RecruitingDefaults
{
    /**
     * @var array<string, array<string, string>>
     */
    private const array STAGE_LABELS = [
        'it' => [
            'applied' => 'Candidatura ricevuta',
            'hr_screening' => 'Screening HR',
            'technical_interview' => 'Colloquio tecnico',
            'manager_interview' => 'Colloquio con il responsabile',
            'offer_extended' => 'Offerta inviata',
            'hired' => 'Assunto',
            'rejected' => 'Non selezionato',
        ],
        'de' => [
            'applied' => 'Bewerbung eingegangen',
            'hr_screening' => 'HR-Screening',
            'technical_interview' => 'Fachgespräch',
            'manager_interview' => 'Gespräch mit der Führungskraft',
            'offer_extended' => 'Angebot versendet',
            'hired' => 'Eingestellt',
            'rejected' => 'Abgesagt',
        ],
        'fr' => [
            'applied' => 'Candidature reçue',
            'hr_screening' => 'Présélection RH',
            'technical_interview' => 'Entretien technique',
            'manager_interview' => 'Entretien avec le responsable',
            'offer_extended' => 'Offre envoyée',
            'hired' => 'Embauché',
            'rejected' => 'Non retenu',
        ],
        'en' => [
            'applied' => 'Applied',
            'hr_screening' => 'HR screening',
            'technical_interview' => 'Technical interview',
            'manager_interview' => 'Manager interview',
            'offer_extended' => 'Offer extended',
            'hired' => 'Hired',
            'rejected' => 'Rejected',
        ],
    ];

    /**
     * Stage-specific sentence of the default email body.
     *
     * @var array<string, array<string, string>>
     */
    private const array STAGE_MESSAGES = [
        'it' => [
            'applied' => 'abbiamo ricevuto la tua candidatura per la posizione "{{job_title}}". Ti contatteremo non appena l\'avremo valutata.',
            'hr_screening' => 'la tua candidatura per "{{job_title}}" è passata alla fase di screening HR. Ti contatteremo a breve per un primo colloquio.',
            'technical_interview' => 'vorremmo invitarti a un colloquio tecnico per la posizione "{{job_title}}". Ti scriveremo per concordare data e ora.',
            'manager_interview' => 'vorremmo invitarti a un colloquio con il responsabile per la posizione "{{job_title}}". Ti scriveremo per concordare data e ora.',
            'offer_extended' => 'siamo lieti di inviarti un\'offerta per la posizione "{{job_title}}". Troverai i dettagli in una comunicazione separata.',
            'hired' => 'benvenuto/a in {{company_name}}! Siamo felici di averti con noi come "{{job_title}}".',
            'rejected' => 'ti ringraziamo per l\'interesse per la posizione "{{job_title}}". Purtroppo abbiamo deciso di proseguire con altri candidati.',
        ],
        'de' => [
            'applied' => 'wir haben Ihre Bewerbung für die Stelle "{{job_title}}" erhalten. Wir melden uns, sobald wir sie geprüft haben.',
            'hr_screening' => 'Ihre Bewerbung für "{{job_title}}" befindet sich im HR-Screening. Wir melden uns in Kürze für ein erstes Gespräch.',
            'technical_interview' => 'wir möchten Sie zu einem Fachgespräch für die Stelle "{{job_title}}" einladen. Wir melden uns zur Terminvereinbarung.',
            'manager_interview' => 'wir möchten Sie zu einem Gespräch mit der Führungskraft für die Stelle "{{job_title}}" einladen. Wir melden uns zur Terminvereinbarung.',
            'offer_extended' => 'wir freuen uns, Ihnen ein Angebot für die Stelle "{{job_title}}" zu unterbreiten. Die Details erhalten Sie separat.',
            'hired' => 'willkommen bei {{company_name}}! Wir freuen uns, Sie als "{{job_title}}" im Team zu haben.',
            'rejected' => 'vielen Dank für Ihr Interesse an der Stelle "{{job_title}}". Leider haben wir uns für andere Kandidierende entschieden.',
        ],
        'fr' => [
            'applied' => 'nous avons bien reçu votre candidature pour le poste « {{job_title}} ». Nous vous contacterons dès que nous l\'aurons examinée.',
            'hr_screening' => 'votre candidature pour « {{job_title}} » est en cours de présélection RH. Nous vous contacterons prochainement pour un premier entretien.',
            'technical_interview' => 'nous souhaitons vous inviter à un entretien technique pour le poste « {{job_title}} ». Nous vous écrirons pour fixer un rendez-vous.',
            'manager_interview' => 'nous souhaitons vous inviter à un entretien avec le responsable pour le poste « {{job_title}} ». Nous vous écrirons pour fixer un rendez-vous.',
            'offer_extended' => 'nous avons le plaisir de vous adresser une offre pour le poste « {{job_title}} ». Vous recevrez les détails séparément.',
            'hired' => 'bienvenue chez {{company_name}} ! Nous sommes ravis de vous accueillir en tant que « {{job_title}} ».',
            'rejected' => 'nous vous remercions de votre intérêt pour le poste « {{job_title}} ». Nous avons malheureusement décidé de poursuivre avec d\'autres candidats.',
        ],
        'en' => [
            'applied' => 'we have received your application for the position "{{job_title}}". We will get back to you as soon as we have reviewed it.',
            'hr_screening' => 'your application for "{{job_title}}" has moved to HR screening. We will contact you shortly for a first conversation.',
            'technical_interview' => 'we would like to invite you to a technical interview for the position "{{job_title}}". We will write to you to agree on a date.',
            'manager_interview' => 'we would like to invite you to an interview with the hiring manager for the position "{{job_title}}". We will write to you to agree on a date.',
            'offer_extended' => 'we are pleased to send you an offer for the position "{{job_title}}". You will receive the details separately.',
            'hired' => 'welcome to {{company_name}}! We are happy to have you on board as "{{job_title}}".',
            'rejected' => 'thank you for your interest in the position "{{job_title}}". Unfortunately we have decided to move forward with other candidates.',
        ],
    ];

    /**
     * @var array<string, array{greeting: string, closing: string, subject: string}>
     */
    private const array EMAIL_FRAME = [
        'it' => ['greeting' => 'Gentile {{candidate_name}},', 'closing' => "Cordiali saluti,\n{{company_name}}", 'subject' => '{{company_name}} - {{job_title}}: {{stage_label}}'],
        'de' => ['greeting' => 'Guten Tag {{candidate_name}},', 'closing' => "Freundliche Grüsse\n{{company_name}}", 'subject' => '{{company_name}} - {{job_title}}: {{stage_label}}'],
        'fr' => ['greeting' => 'Bonjour {{candidate_name}},', 'closing' => "Meilleures salutations,\n{{company_name}}", 'subject' => '{{company_name}} - {{job_title}} : {{stage_label}}'],
        'en' => ['greeting' => 'Dear {{candidate_name}},', 'closing' => "Kind regards,\n{{company_name}}", 'subject' => '{{company_name}} - {{job_title}}: {{stage_label}}'],
    ];

    /**
     * @var array<string, string>
     */
    private const array APPLICATION_RECEIVED = [
        'it' => 'Grazie, la tua candidatura è stata ricevuta.',
        'de' => 'Vielen Dank, Ihre Bewerbung ist eingegangen.',
        'fr' => 'Merci, votre candidature a bien été reçue.',
        'en' => 'Thank you, your application has been received.',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const array JOB_SECTIONS = [
        'it' => ['requirements' => 'Requisiti', 'benefits' => 'Cosa offriamo'],
        'de' => ['requirements' => 'Anforderungen', 'benefits' => 'Was wir bieten'],
        'fr' => ['requirements' => 'Profil recherché', 'benefits' => 'Ce que nous offrons'],
        'en' => ['requirements' => 'Requirements', 'benefits' => 'What we offer'],
    ];

    /**
     * Offer letter template; placeholders are replaced with HTML-escaped values by `JobOfferLetterRenderer`.
     *
     * @var array<string, string>
     */
    private const array OFFER_LETTER = [
        'it' => '<p>Gentile {{candidate_name}},</p><p>{{company_name}} è lieta di offrirti la posizione di <strong>{{job_title}}</strong> alle seguenti condizioni:</p><ul><li>Retribuzione annua lorda: {{salary}} {{currency}}</li><li>Data di inizio: {{start_date}}</li><li>Tipo di impiego: {{employment_type}}</li><li>Tipo di contratto: {{contract_type}}</li><li>Ore settimanali: {{weekly_hours}}</li><li>Periodo di prova: {{probation_months}} mesi</li></ul><p>L\'offerta è valida fino al {{valid_until}}.</p><p>Cordiali saluti,<br>{{company_name}}</p>',
        'de' => '<p>Guten Tag {{candidate_name}},</p><p>{{company_name}} freut sich, Ihnen die Stelle als <strong>{{job_title}}</strong> zu folgenden Bedingungen anzubieten:</p><ul><li>Bruttojahreslohn: {{salary}} {{currency}}</li><li>Eintrittsdatum: {{start_date}}</li><li>Anstellungsart: {{employment_type}}</li><li>Vertragsart: {{contract_type}}</li><li>Wochenstunden: {{weekly_hours}}</li><li>Probezeit: {{probation_months}} Monate</li></ul><p>Das Angebot ist gültig bis {{valid_until}}.</p><p>Freundliche Grüsse<br>{{company_name}}</p>',
        'fr' => '<p>Bonjour {{candidate_name}},</p><p>{{company_name}} a le plaisir de vous proposer le poste de <strong>{{job_title}}</strong> aux conditions suivantes :</p><ul><li>Salaire annuel brut : {{salary}} {{currency}}</li><li>Date d\'entrée : {{start_date}}</li><li>Type d\'emploi : {{employment_type}}</li><li>Type de contrat : {{contract_type}}</li><li>Heures hebdomadaires : {{weekly_hours}}</li><li>Période d\'essai : {{probation_months}} mois</li></ul><p>L\'offre est valable jusqu\'au {{valid_until}}.</p><p>Meilleures salutations,<br>{{company_name}}</p>',
        'en' => '<p>Dear {{candidate_name}},</p><p>{{company_name}} is pleased to offer you the position of <strong>{{job_title}}</strong> on the following terms:</p><ul><li>Gross annual salary: {{salary}} {{currency}}</li><li>Start date: {{start_date}}</li><li>Employment type: {{employment_type}}</li><li>Contract type: {{contract_type}}</li><li>Weekly hours: {{weekly_hours}}</li><li>Probation period: {{probation_months}} months</li></ul><p>This offer is valid until {{valid_until}}.</p><p>Kind regards,<br>{{company_name}}</p>',
    ];

    /**
     * Stages that send an email automatically when no pipeline setting overrides it.
     *
     * @var list<string>
     */
    private const array AUTO_EMAIL_STAGES = ['applied', 'technical_interview', 'manager_interview', 'rejected'];

    public static function stageLabel(ApplicationStage $stage, RecruitingLocale $locale): string
    {
        return self::STAGE_LABELS[$locale->language()][$stage->value];
    }

    /**
     * @return array<string, string> keyed by every `RecruitingLocale` value
     */
    public static function stageLabels(ApplicationStage $stage): array
    {
        $labels = [];
        foreach (RecruitingLocale::cases() as $locale) {
            $labels[$locale->value] = self::stageLabel($stage, $locale);
        }

        return $labels;
    }

    public static function autoEmail(ApplicationStage $stage): bool
    {
        return in_array($stage->value, self::AUTO_EMAIL_STAGES, true);
    }

    /**
     * @return array{subject: string, body: string}
     */
    public static function emailTemplate(ApplicationStage $stage, RecruitingLocale $locale): array
    {
        $language = $locale->language();
        $frame = self::EMAIL_FRAME[$language];

        return [
            'subject' => $frame['subject'],
            'body' => $frame['greeting']."\n\n".ucfirst(self::STAGE_MESSAGES[$language][$stage->value])."\n\n".$frame['closing'],
        ];
    }

    public static function applicationReceivedMessage(RecruitingLocale $locale): string
    {
        return self::APPLICATION_RECEIVED[$locale->language()];
    }

    public static function jobSectionLabel(string $section, RecruitingLocale $locale): string
    {
        return self::JOB_SECTIONS[$locale->language()][$section] ?? ucfirst($section);
    }

    public static function offerLetterTemplate(RecruitingLocale $locale): string
    {
        return self::OFFER_LETTER[$locale->language()];
    }
}
