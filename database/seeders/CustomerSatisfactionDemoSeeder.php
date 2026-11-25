<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\CustomerSatisfaction\Contracts\SentimentAnalyzerInterface;
use Modules\CustomerSatisfaction\Domain\Enums\DispatchStatus;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryStatus;
use Modules\CustomerSatisfaction\Domain\Enums\SubmittedVia;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;
use Modules\CustomerSatisfaction\Domain\Support\SurveyLocale;
use Modules\CustomerSatisfaction\Models\DetractorRecoveryCase;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Models\SurveyResponse;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;
use Modules\CustomerSatisfaction\Services\DetractorRecoveryService;
use Modules\CustomerSatisfaction\Services\SurveyTemplateDefaults;

/**
 * Customer satisfaction (Mod 245) for the first company of the installation: the stock NPS, CSAT and CES templates for the five ERP
 * trigger events and, so the dashboard, the recovery queue and the ISO 9001 reports are populated in demo environments, about
 * thirty answers over the last ninety days (departments, product lines, branches, comments with sentiment) with the closed-loop cases
 * of the detractors. Idempotent: the templates are keyed by code and the demo answers are written only while the company has none.
 * Names and comments are demo content; the interface texts are in the frontend dictionary seeders.
 */
final class CustomerSatisfactionDemoSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string}> department, product line, branch */
    private const array Dimensions = [
        ['support', 'erp-cloud', 'lugano'],
        ['support', 'erp-cloud', 'milano'],
        ['field-service', 'hardware', 'lugano'],
        ['logistics', 'hardware', 'zurich'],
        ['onboarding', 'erp-cloud', 'milano'],
    ];

    /** @var list<string> */
    private const array Comments = [
        'Ottimo servizio, tecnico veloce e gentile. Grazie!',
        'Great support, the problem was solved quickly.',
        'Die Lieferung kam mit grosser Verspätung, das war ein Problem.',
        'Intervention rapide et professionnelle, merci.',
        'Prezzo troppo caro e assistenza lenta, sono deluso.',
        'The delivery was late and nobody answered my emails. Very bad.',
        'Qualità perfetta, comunicazione chiara e precisa.',
        'Der Techniker war freundlich, alles einfach und schnell.',
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant): void {
            SurveyTemplateDefaults::install();

            $customers = Contact::query()->where('type', 'customer')->orderBy('created_at')->limit(8)->get();

            if ($customers->isEmpty() || SurveyResponse::query()->exists()) {
                return;
            }

            $this->answers((string) $tenant->id, $customers->all());
        });
    }

    /**
     * @param  list<Contact>  $customers
     */
    private function answers(string $tenantId, array $customers): void
    {
        $templates = SurveyTemplate::query()->get()->keyBy('code');
        $recovery = app(DetractorRecoveryService::class);
        $sentiment = app(SentimentAnalyzerInterface::class);

        // [template code, score, days ago, comment index or null]
        $plan = [
            ['NPS-TICKET-CLOSED', 10, 85, 0], ['NPS-TICKET-CLOSED', 9, 80, 1], ['NPS-TICKET-CLOSED', 8, 74, null], ['NPS-TICKET-CLOSED', 3, 70, 4],
            ['NPS-TICKET-CLOSED', 10, 63, 6], ['NPS-TICKET-CLOSED', 7, 58, null], ['NPS-TICKET-CLOSED', 2, 41, 5], ['NPS-TICKET-CLOSED', 9, 33, 3],
            ['CSAT-ORDER-DELIVERED', 5, 82, 6], ['CSAT-ORDER-DELIVERED', 4, 76, null], ['CSAT-ORDER-DELIVERED', 1, 68, 2], ['CSAT-ORDER-DELIVERED', 5, 55, 7],
            ['CSAT-ORDER-DELIVERED', 3, 47, null], ['CSAT-ORDER-DELIVERED', 2, 29, 5], ['CSAT-ORDER-DELIVERED', 4, 22, 1], ['CSAT-ORDER-DELIVERED', 5, 15, null],
            ['CES-FIELD-INTERVENTION', 2, 79, 7], ['CES-FIELD-INTERVENTION', 1, 66, null], ['CES-FIELD-INTERVENTION', 6, 52, 4], ['CES-FIELD-INTERVENTION', 3, 38, 3],
            ['CES-FIELD-INTERVENTION', 7, 26, 5], ['CES-FIELD-INTERVENTION', 2, 12, null],
            ['CSAT-ONBOARDING', 5, 60, 0], ['CSAT-ONBOARDING', 4, 44, null], ['CSAT-ONBOARDING', 5, 20, 1],
            ['NPS-SEMI-ANNUAL', 9, 30, null], ['NPS-SEMI-ANNUAL', 10, 18, 6], ['NPS-SEMI-ANNUAL', 6, 9, 4], ['NPS-SEMI-ANNUAL', 8, 5, null], ['NPS-SEMI-ANNUAL', 9, 2, 3],
        ];

        foreach ($plan as $index => [$code, $score, $daysAgo, $commentIndex]) {
            /** @var SurveyTemplate|null $template */
            $template = $templates->get($code);

            if ($template === null) {
                continue;
            }

            $customer = $customers[$index % count($customers)];
            $dimension = self::Dimensions[$index % count(self::Dimensions)];
            $moment = now()->subDays($daysAgo)->setTime(10 + ($index % 6), 15);
            $type = SurveyType::from($template->survey_type->value);
            $locale = SurveyLocale::forCountry($customer->country);

            $dispatch = SurveyDispatch::query()->create([
                'survey_template_id' => $template->id,
                'customer_id' => $customer->id,
                'contact_id' => $customer->id,
                'trigger_event' => $template->trigger_event->value,
                'source_entity_type' => 'demo',
                'source_entity_id' => (string) Str::uuid7(),
                'channel' => 'email',
                'locale' => $locale,
                'dispatch_token' => Str::random(48),
                'status' => DispatchStatus::Responded->value,
                'scheduled_for' => $moment->copy()->subHours(2),
                'sent_at' => $moment->copy()->subHour(),
                'responded_at' => $moment,
                'metadata' => ['demo' => true],
            ]);
            $dispatch->forceFill(['created_at' => $moment->copy()->subHours(3)])->save();

            $comment = $commentIndex === null ? null : self::Comments[$commentIndex];
            $analysis = $comment === null ? null : $sentiment->analyze($comment, SurveyLocale::language($locale));

            $response = SurveyResponse::query()->create([
                'survey_dispatch_id' => $dispatch->id,
                'survey_template_id' => $template->id,
                'customer_id' => $customer->id,
                'contact_id' => $customer->id,
                'survey_type' => $type->value,
                'score' => $score,
                'normalized_band' => $type->band($score)->value,
                'is_detractor' => $type->isDetractor($score, $template->effectiveDetractorThreshold()),
                'comment_text' => $comment,
                'sentiment_score' => $analysis['score'] ?? null,
                'sentiment_label' => $analysis['label'] ?? null,
                'sentiment_topics' => $analysis['topics'] ?? null,
                'department_code' => $dimension[0],
                'product_line_code' => $dimension[1],
                'branch_code' => $dimension[2],
                'submitted_via' => ($index % 3 === 0 ? SubmittedVia::LandingPage : SubmittedVia::OneClickLink)->value,
                'locale' => $locale,
                'metadata' => ['demo' => true],
                'initial_clicked_at' => $moment,
                'comment_updated_at' => $comment === null ? null : $moment->copy()->addMinutes(3),
            ]);

            if ($response->is_detractor) {
                $this->worked($recovery->open($response, $template), $recovery, $daysAgo);
            }
        }
    }

    /**
     * Older cases of the demo are already worked or solved, so the queue holds a mix of open, overdue and solved cases.
     */
    private function worked(DetractorRecoveryCase $case, DetractorRecoveryService $recovery, int $daysAgo): void
    {
        $created = now()->subDays($daysAgo);
        $case->forceFill(['created_at' => $created, 'sla_due_at' => $created->copy()->addHours(24)])->save();

        if ($daysAgo > 60) {
            $case->forceFill(['first_contacted_at' => $created->copy()->addHours(5)])->save();
            $recovery->transition($case, RecoveryStatus::Resolved, 'delivery_delay', 'Chiamata al cliente, rimborso del trasporto e nuova consegna prioritaria.');
        } elseif ($daysAgo > 30) {
            $case->forceFill(['first_contacted_at' => $created->copy()->addHours(30)])->save();
            $recovery->transition($case, RecoveryStatus::Resolved, 'service_quality', 'Escalation al responsabile, intervento correttivo e nuova formazione del tecnico.');
        }
    }
}
