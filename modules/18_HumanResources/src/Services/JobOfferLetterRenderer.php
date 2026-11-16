<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\JobOffer;
use Modules\HumanResources\Support\RecruitingDefaults;

/**
 * Renders the HTML body of an offer letter from the built-in localised template.
 */
final readonly class JobOfferLetterRenderer
{
    public function __construct(
        private RecruitmentServiceInterface $recruitment
    ) {}

    public function render(JobOffer $offer, CandidateApplication $application): string
    {
        $locale = RecruitingLocale::tryFrom((string) $application->locale) ?? RecruitingLocale::En;
        $opening = $application->jobOpening;

        $values = [
            '{{candidate_name}}' => $application->fullName(),
            '{{company_name}}' => $this->recruitment->companyName(),
            '{{job_title}}' => $opening !== null ? $opening->localized($locale->value)['title'] : '',
            '{{salary}}' => number_format((float) $offer->proposed_salary, 2, '.', "'"),
            '{{currency}}' => (string) $offer->currency,
            '{{start_date}}' => $offer->start_date->toDateString(),
            '{{employment_type}}' => (string) $offer->employment_type,
            '{{contract_type}}' => (string) $offer->contract_type,
            '{{weekly_hours}}' => rtrim(rtrim(number_format((float) $offer->weekly_hours, 2, '.', ''), '0'), '.'),
            '{{probation_months}}' => (string) $offer->probation_months,
            '{{valid_until}}' => $offer->valid_until?->toDateString() ?? '-',
        ];

        return strtr(
            RecruitingDefaults::offerLetterTemplate($locale),
            array_map(static fn (string $value): string => e($value), $values)
        );
    }
}
