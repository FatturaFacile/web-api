<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use Modules\CustomerSatisfaction\Models\SurveyDispatch;

/**
 * Tokenized public URLs of a survey: one link per score (the click itself records the answer) and the mobile landing page.
 * The 48 character random token is the signature: it cannot be guessed and only addresses its own dispatch.
 */
final class SurveyLinkBuilder
{
    public function oneClickUrl(SurveyDispatch|string $dispatch, int $score): string
    {
        return url('/api/v1/public/surveys/'.$this->token($dispatch).'/rate/'.$score);
    }

    public function landingUrl(SurveyDispatch|string $dispatch): string
    {
        return rtrim((string) config('customer_satisfaction.landing_url'), '/').'/'.$this->token($dispatch);
    }

    public function optOutUrl(SurveyDispatch|string $dispatch): string
    {
        return url('/api/v1/public/surveys/'.$this->token($dispatch).'/opt-out');
    }

    private function token(SurveyDispatch|string $dispatch): string
    {
        return $dispatch instanceof SurveyDispatch ? $dispatch->dispatch_token : $dispatch;
    }
}
