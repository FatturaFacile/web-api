<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\TalentLearning\Models\EngagementQuestion;
use Modules\TalentLearning\Models\EngagementSurvey;
use Modules\TalentLearning\Models\LmsCourse;

/**
 * Reference data of the talent and learning module for every company: the usual compliance courses (as drafts, ready
 * to be completed and published) and an annual engagement survey template (a draft, anonymous, with the eNPS question).
 * Idempotent: courses are matched on their code, the survey on its title and the questions on their position,
 * including the records a company deleted, so a rerun never duplicates or resurrects anything.
 */
final class TalentLearningReferenceSeeder extends Seeder
{
    public const string SurveyTitle = 'Annual engagement survey';

    /**
     * Code, title, delivery, duration in hours, validity in months and pass score of every compliance course.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int|null, 5: int|null}>
     */
    private const array Courses = [
        ['SAFETY-BASIC', 'Workplace health and safety', 'online', '4.0', 60, 80],
        ['SAFETY-FIRE', 'Fire safety and evacuation', 'classroom', '4.0', 60, null],
        ['FIRST-AID', 'First aid', 'classroom', '12.0', 36, null],
        ['DATA-PROTECTION', 'Data protection and privacy', 'online', '2.0', 24, 80],
        ['INFO-SECURITY', 'Information security awareness', 'online', '1.5', 12, 80],
        ['ANTI-CORRUPTION', 'Anti-corruption and code of conduct', 'online', '1.5', 24, 80],
        ['HARASSMENT-PREVENTION', 'Preventing harassment and discrimination', 'online', '1.0', 24, 80],
    ];

    /**
     * Prompt and question type of the survey, in order.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array Questions = [
        ['I understand how my work contributes to the goals of the company.', EngagementQuestion::Rating],
        ['I have the tools and resources I need to do my job well.', EngagementQuestion::Rating],
        ['I receive regular and useful feedback from my manager.', EngagementQuestion::Rating],
        ['I see opportunities to learn and grow here.', EngagementQuestion::Rating],
        ['I feel respected and included in my team.', EngagementQuestion::Rating],
        ['How likely are you to recommend this company as a place to work?', EngagementQuestion::Enps],
        ['What is the one thing we should improve first?', EngagementQuestion::Text],
    ];

    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $context->setTenant($tenant);

            try {
                $this->seedTenant((string) $tenant->id);
            } finally {
                $context->clear();
            }
        }
    }

    private function seedTenant(string $tenantId): void
    {
        foreach (self::Courses as [$code, $title, $delivery, $hours, $validityMonths, $passScore]) {
            if (LmsCourse::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                continue;
            }

            LmsCourse::create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'title' => $title,
                'delivery' => $delivery,
                'duration_hours' => $hours,
                'is_mandatory' => true,
                'validity_months' => $validityMonths,
                'pass_score' => $passScore,
                'status' => LmsCourse::Draft,
            ]);
        }

        $survey = EngagementSurvey::withTrashed()->where('tenant_id', $tenantId)->where('title', self::SurveyTitle)->first();

        if ($survey === null) {
            $survey = EngagementSurvey::create([
                'tenant_id' => $tenantId,
                'title' => self::SurveyTitle,
                'description' => 'Yearly pulse on engagement, growth and the likelihood to recommend the company.',
                'status' => EngagementSurvey::Draft,
                'is_anonymous' => true,
                'min_group_size' => 5,
            ]);
        }

        foreach (self::Questions as $index => [$prompt, $type]) {
            if (EngagementQuestion::withTrashed()->where('survey_id', $survey->id)->where('position', $index + 1)->exists()) {
                continue;
            }

            EngagementQuestion::create(['tenant_id' => $tenantId, 'survey_id' => $survey->id, 'position' => $index + 1, 'prompt' => $prompt, 'question_type' => $type, 'is_required' => $type !== EngagementQuestion::Text]);
        }
    }
}
