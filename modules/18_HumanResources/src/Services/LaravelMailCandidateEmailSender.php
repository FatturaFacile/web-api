<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Illuminate\Support\Facades\Mail;
use Modules\HumanResources\Contracts\CandidateEmailSenderInterface;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\EmailStatus;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Mail\CandidateStageMail;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\CandidateEmail;
use Throwable;

/**
 * Default candidate email sender based on Laravel Mail (queued mailable). Every attempt is logged in
 * `rec_candidate_emails`, including skipped ones (disabled template, anonymized candidate, no address).
 */
final readonly class LaravelMailCandidateEmailSender implements CandidateEmailSenderInterface
{
    public function __construct(
        private RecruitmentServiceInterface $recruitment
    ) {}

    public function sendStageEmail(CandidateApplication $application, ApplicationStage $stage): CandidateEmail
    {
        $locale = RecruitingLocale::tryFrom((string) $application->locale) ?? RecruitingLocale::En;
        $template = $this->recruitment->emailTemplate($stage, $locale);
        $recipient = $application->isAnonymized() ? null : $application->email;

        $log = new CandidateEmail([
            'application_id' => $application->id,
            'stage' => $stage->value,
            'locale' => $locale->value,
            'recipient_email' => $recipient,
        ]);
        $log->tenant_id = $application->tenant_id;

        if ($recipient === null || $recipient === '') {
            return $this->finish($log, EmailStatus::Skipped, null, 'no_recipient');
        }

        if (! $template['is_enabled']) {
            return $this->finish($log, EmailStatus::Skipped, null, 'template_disabled');
        }

        $settings = $this->recruitment->settings();
        $opening = $application->jobOpening;
        $values = [
            '{{candidate_name}}' => $application->fullName(),
            '{{job_title}}' => $opening !== null ? $opening->localized($locale->value)['title'] : '',
            '{{company_name}}' => $this->recruitment->companyName($settings),
            '{{stage_label}}' => $settings->stageLabel($stage, $locale),
        ];

        $subject = trim((string) preg_replace('/\s+/', ' ', strtr($template['subject'], $values)));
        $html = $this->renderBody($template['body'], $values);

        try {
            Mail::to($recipient)->queue(new CandidateStageMail($subject, $html));
        } catch (Throwable $exception) {
            return $this->finish($log, EmailStatus::Failed, $subject, class_basename($exception));
        }

        return $this->finish($log, EmailStatus::Queued, $subject, null);
    }

    /**
     * Template bodies are plain text unless they contain markup; placeholder values are always HTML-escaped.
     *
     * @param  array<string, string>  $values
     */
    private function renderBody(string $body, array $values): string
    {
        $html = $body !== strip_tags($body) ? $body : nl2br(e($body), false);

        return strtr($html, array_map(static fn (string $value): string => e($value), $values));
    }

    private function finish(CandidateEmail $log, EmailStatus $status, ?string $subject, ?string $failureReason): CandidateEmail
    {
        $log->fill([
            'subject' => $subject !== null ? mb_substr($subject, 0, 255) : null,
            'status' => $status,
            'failure_reason' => $failureReason,
        ]);
        $log->save();

        return $log;
    }
}
