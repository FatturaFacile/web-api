<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use App\Models\Contact;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;
use Modules\CustomerSatisfaction\Domain\Support\SurveyLocale;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;

/**
 * Builds the invitation of a dispatch from the localized texts of its template: subject, body and, on email, one link per score so
 * a single click records the answer. SMS and WhatsApp carry the landing page link (the interactive WhatsApp reply posts the score).
 */
final class SurveyMessageComposer
{
    public function __construct(private readonly SurveyLinkBuilder $links) {}

    /**
     * @return array{subject: string, body: string}
     */
    public function compose(SurveyDispatch $dispatch, SurveyTemplate $template, ?Contact $contact): array
    {
        /** @var array<string, string>|null $texts */
        $texts = SurveyLocale::pick($template->messages, $dispatch->locale);
        $name = $contact === null ? '' : (string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name));

        $subject = $this->fill((string) ($texts['subject'] ?? $template->name), $name);
        $body = $this->fill((string) ($texts['body'] ?? ''), $name);
        $label = (string) ($texts['one_click_label'] ?? '');

        if ($dispatch->channel === SurveyChannel::Email) {
            $lines = [];

            for ($score = $template->survey_type->min(); $score <= $template->survey_type->max(); $score++) {
                $lines[] = $score.' → '.$this->links->oneClickUrl($dispatch, $score);
            }

            $body .= "\n\n".($label !== '' ? $label."\n" : '').implode("\n", $lines);
        } else {
            $body .= "\n\n".$this->links->landingUrl($dispatch);
        }

        return ['subject' => $subject, 'body' => $body."\n\n".$this->links->optOutUrl($dispatch)];
    }

    private function fill(string $text, string $name): string
    {
        return str_replace('{customer_name}', $name, $text);
    }
}
