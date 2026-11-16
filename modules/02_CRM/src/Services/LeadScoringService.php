<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Modules\CRM\Models\Lead;

/**
 * Weighted lead scoring: demographic fit (role, e-mail, company, budget, industry, revenue, geography)
 * plus behavioural engagement (e-mail opens, catalogue downloads, website visits). Capped at 100.
 */
final class LeadScoringService
{
    /**
     * @return array{score: int, rating: string, breakdown: array<string, int>}
     */
    public function evaluate(Lead $lead): array
    {
        $b = [];

        // Demographic
        if (preg_match('/(director|direttore|vp|c-level|ceo|cfo|cto|coo|head|chief|president|founder|owner|titolare|partner|manager|responsabile)/', strtolower((string) $lead->title))) {
            $b['seniority'] = 20;
        }

        $email = strtolower((string) $lead->email);
        if ($email !== '') {
            $domain = substr(strrchr($email, '@') ?: '', 1);
            $free = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'libero.it', 'icloud.com'];
            $b['email'] = ($domain !== '' && ! in_array($domain, $free, true)) ? 15 : 5;
        }

        if (! empty($lead->phone)) {
            $b['phone'] = 10;
        }
        if (! empty($lead->company_name)) {
            $b['company'] = 15;
        }

        $budget = (float) $lead->estimated_budget;
        if ($budget > 0.0) {
            $b['budget'] = $budget >= 50000.0 ? 30 : ($budget >= 10000.0 ? 20 : 10);
        }

        if (! empty($lead->website)) {
            $b['website'] = 10;
        }

        $industries = (array) config('crm_lead.scoring.industries', []);
        $industry = strtolower((string) $lead->industry);
        if ($industry !== '' && isset($industries[$industry])) {
            $b['industry'] = (int) $industries[$industry];
        }

        $revenue = (float) $lead->estimated_revenue;
        if ($revenue > 0.0) {
            $tiers = (array) config('crm_lead.scoring.revenue_tiers', []);
            krsort($tiers);
            foreach ($tiers as $min => $points) {
                if ($revenue >= (float) $min) {
                    $b['revenue'] = (int) $points;
                    break;
                }
            }
        }

        $regions = (array) config('crm_lead.scoring.regions', []);
        $region = strtolower((string) $lead->region);
        if ($region !== '' && isset($regions[$region])) {
            $b['geography'] = (int) $regions[$region];
        }

        // Behavioural
        foreach ((array) config('crm_lead.scoring.behaviour', []) as $counter => $rule) {
            $points = min((int) $rule['cap'], (int) $lead->{$counter} * (int) $rule['points']);
            if ($points > 0) {
                $b[$counter] = $points;
            }
        }

        $score = min(100, array_sum($b));

        return ['score' => $score, 'rating' => self::ratingFor($score), 'breakdown' => $b];
    }

    public static function ratingFor(int $score): string
    {
        return $score >= (int) config('crm_lead.thresholds.hot', 70)
            ? 'hot'
            : ($score >= (int) config('crm_lead.thresholds.warm', 40) ? 'warm' : 'cold');
    }

    /** Visual label (Freddo / Tiepido / Caldo) for a rating, translated to the current locale. */
    public static function labelFor(?string $rating): string
    {
        return __('crm.lead_rating_'.($rating ?: 'cold'));
    }
}
