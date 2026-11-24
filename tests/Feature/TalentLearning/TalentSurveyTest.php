<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\EngagementAnswer;
use Modules\TalentLearning\Models\EngagementParticipant;
use Modules\TalentLearning\Models\EngagementResponse;

require_once __DIR__.'/TalentLearningSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = tlTenant('Acme');
    $this->hr = tlMember($this->tenant, 'hr');
    tlAct($this->hr, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * An open survey with a rating, an eNPS and a text question.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tlSurvey(array $overrides = [], bool $open = true): array
{
    $survey = test()->postJson('/api/v1/talent-learning/surveys', [
        'title' => 'Pulse June',
        'min_group_size' => 3,
        'questions' => [
            ['prompt' => 'I feel valued', 'question_type' => 'rating'],
            ['prompt' => 'Would you recommend us?', 'question_type' => 'enps'],
            ['prompt' => 'Anything else?', 'question_type' => 'text', 'is_required' => false],
        ],
        ...$overrides,
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

    return $open ? test()->postJson("/api/v1/talent-learning/surveys/{$survey['id']}/open")->assertOk()->assertJsonPath('data.status', 'open')->json('data') : $survey;
}

/**
 * @param  array<string, mixed>  $survey
 * @return array<int, array<string, mixed>>
 */
function tlAnswers(array $survey, int $rating, int $enps, ?string $text = null): array
{
    $answers = [['question_id' => $survey['questions'][0]['id'], 'value' => $rating], ['question_id' => $survey['questions'][1]['id'], 'value' => $enps]];

    return $text === null ? $answers : [...$answers, ['question_id' => $survey['questions'][2]['id'], 'text' => $text]];
}

/**
 * One respondent per call, from the given department.
 *
 * @param  array<string, mixed>  $survey
 * @return array{user: User, employee: Model}
 */
function tlRespond(array $survey, string $departmentName, int $rating, int $enps, ?string $text = null): array
{
    static $departments = [];
    $key = $departmentName.test()->tenant->id;
    $departments[$key] ??= tlDepartment(test()->tenant, $departmentName);
    $learner = tlLearner(test()->tenant, ['department_id' => $departments[$key]->id]);
    tlAct($learner['user'], test()->tenant);
    test()->postJson("/api/v1/talent-learning/surveys/{$survey['id']}/responses", ['answers' => tlAnswers($survey, $rating, $enps, $text)])->assertNoContent();

    return $learner;
}

test('a survey is built in draft, needs questions to open and is frozen afterwards', function (): void {
    $this->postJson('/api/v1/talent-learning/surveys', ['title' => 'Empty', 'questions' => []])->assertUnprocessable()->assertJsonValidationErrors('questions');
    $this->postJson('/api/v1/talent-learning/surveys', ['title' => 'Bad', 'min_group_size' => 2, 'questions' => [['prompt' => 'q', 'question_type' => 'stars']]])->assertUnprocessable()->assertJsonValidationErrors(['min_group_size', 'questions.0.question_type']);

    $draft = tlSurvey(open: false);
    $this->assertTrue($draft['is_anonymous']);
    $this->assertCount(3, $draft['questions']);
    $this->putJson("/api/v1/talent-learning/surveys/{$draft['id']}", ['title' => 'Renamed', 'questions' => [['prompt' => 'Only one', 'question_type' => 'rating']]])->assertOk()->assertJsonCount(1, 'data.questions')->assertJsonPath('data.title', 'Renamed');
    $this->postJson("/api/v1/talent-learning/surveys/{$draft['id']}/close")->assertUnprocessable();
    $this->postJson("/api/v1/talent-learning/surveys/{$draft['id']}/open")->assertOk();
    $this->putJson("/api/v1/talent-learning/surveys/{$draft['id']}", ['title' => 'Late'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->deleteJson("/api/v1/talent-learning/surveys/{$draft['id']}")->assertUnprocessable();
    $this->postJson("/api/v1/talent-learning/surveys/{$draft['id']}/open")->assertUnprocessable();
    $this->postJson("/api/v1/talent-learning/surveys/{$draft['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');

    $another = tlSurvey(open: false);
    $this->deleteJson("/api/v1/talent-learning/surveys/{$another['id']}")->assertNoContent();
});

test('members only see open surveys, answer them once and cannot read the results', function (): void {
    $open = tlSurvey();
    $draft = tlSurvey(['title' => 'Hidden draft'], false);
    $learner = tlLearner($this->tenant);

    tlAct($learner['user'], $this->tenant);
    $this->getJson('/api/v1/talent-learning/surveys')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open['id'])->assertJsonPath('data.0.has_answered', false);
    $this->getJson("/api/v1/talent-learning/surveys/{$draft['id']}")->assertNotFound();
    $this->getJson("/api/v1/talent-learning/surveys/{$open['id']}")->assertOk()->assertJsonCount(3, 'data.questions');
    $this->getJson("/api/v1/talent-learning/surveys/{$open['id']}/results")->assertForbidden();
    $this->postJson('/api/v1/talent-learning/surveys', ['title' => 'Mine', 'questions' => [['prompt' => 'q', 'question_type' => 'text']]])->assertForbidden();
    $this->postJson("/api/v1/talent-learning/surveys/{$draft['id']}/responses", ['answers' => tlAnswers($draft, 3, 8)])->assertUnprocessable();

    $this->postJson("/api/v1/talent-learning/surveys/{$open['id']}/responses", ['answers' => tlAnswers($open, 4, 9)])->assertNoContent();
    $this->postJson("/api/v1/talent-learning/surveys/{$open['id']}/responses", ['answers' => tlAnswers($open, 4, 9)])->assertUnprocessable()->assertJsonValidationErrors('survey');
    $this->getJson('/api/v1/talent-learning/surveys')->assertJsonPath('data.0.has_answered', true);

    tlAct(tlMember($this->tenant, 'member'), $this->tenant);
    $this->postJson("/api/v1/talent-learning/surveys/{$open['id']}/responses", ['answers' => tlAnswers($open, 4, 9)])->assertUnprocessable()->assertJsonValidationErrors('survey');
});

test('answers are validated against the type of the question', function (): void {
    $survey = tlSurvey();
    $learner = tlLearner($this->tenant);
    tlAct($learner['user'], $this->tenant);
    $url = "/api/v1/talent-learning/surveys/{$survey['id']}/responses";

    $this->postJson($url, ['answers' => tlAnswers($survey, 6, 8)])->assertUnprocessable()->assertJsonValidationErrors('answers.0.value');
    $this->postJson($url, ['answers' => tlAnswers($survey, 0, 8)])->assertUnprocessable()->assertJsonValidationErrors('answers.0.value');
    $this->postJson($url, ['answers' => tlAnswers($survey, 3, 11)])->assertUnprocessable()->assertJsonValidationErrors('answers.1.value');
    $this->postJson($url, ['answers' => [['question_id' => $survey['questions'][0]['id'], 'value' => 3]]])->assertUnprocessable()->assertJsonValidationErrors('answers');
    $this->postJson($url, ['answers' => [...tlAnswers($survey, 3, 8), ['question_id' => $survey['questions'][0]['id'], 'value' => 2]]])->assertUnprocessable();
    $this->postJson($url, ['answers' => [...tlAnswers($survey, 3, 8), ['question_id' => (string) Str::uuid(), 'value' => 2]]])->assertUnprocessable();
    $this->assertSame(0, EngagementResponse::query()->count());

    $this->postJson($url, ['answers' => tlAnswers($survey, 5, 0)])->assertNoContent();
    $this->assertSame(1, EngagementResponse::query()->count());
});

test('an anonymous response never stores who answered and is not linked to the participation', function (): void {
    $survey = tlSurvey();
    $learner = tlRespond($survey, 'Sales', 4, 9, 'More flexibility please');

    $response = EngagementResponse::query()->firstOrFail();
    $this->assertNull($response->respondent_employee_id);
    $this->assertSame('Sales', $response->segment);
    $this->assertSame('2027-06-15', $response->submitted_on->toDateString());
    $this->assertSame(1, EngagementParticipant::query()->where('employee_id', $learner['employee']->id)->count());

    $stored = json_encode([DB::table('tl_survey_responses')->get(), DB::table('tl_survey_answers')->get()]);
    $this->assertStringNotContainsString($learner['employee']->id, $stored);
    $this->assertStringNotContainsString($learner['user']->id, $stored);
    $this->assertSame(3, EngagementAnswer::query()->where('response_id', $response->id)->count());
    $this->assertSame([], array_values(array_intersect(Schema::getColumnListing('tl_survey_participants'), ['response_id'])));
    $this->assertSame([], array_values(array_intersect(Schema::getColumnListing('tl_survey_responses'), ['created_at', 'updated_at', 'user_id'])));
    $this->assertSame(0, DB::table('enterprise_audit_ledger')->whereIn('resource_id', [$response->id, ...EngagementAnswer::query()->pluck('id')->all()])->count());
});

test('a survey that is not anonymous keeps the respondent', function (): void {
    $survey = tlSurvey(['is_anonymous' => false]);
    $learner = tlRespond($survey, 'Sales', 4, 9);

    $this->assertSame($learner['employee']->id, EngagementResponse::query()->firstOrFail()->respondent_employee_id);
});

test('anonymous results stay hidden until the minimum group size is reached', function (): void {
    $survey = tlSurvey();
    tlRespond($survey, 'Sales', 4, 10);
    tlRespond($survey, 'Sales', 3, 6);
    tlAct($this->hr, $this->tenant);

    $results = $this->getJson("/api/v1/talent-learning/surveys/{$survey['id']}/results")->assertOk()
        ->assertJsonPath('data.responses_count', 2)
        ->assertJsonPath('data.results_visible', false)
        ->assertJsonPath('data.invited_count', 2)
        ->assertJsonPath('data.participation_rate_percent', 100)
        ->assertJsonPath('data.questions', [])
        ->assertJsonPath('data.segments', []);
    $this->assertNull($results->json('data.questions.0'));
    $this->getJson('/api/v1/talent-learning/dashboard')->assertOk()->assertJsonPath('data.engagement.results_visible', false)->assertJsonPath('data.engagement.enps', null);

    tlRespond($survey, 'Sales', 5, 9);
    tlAct($this->hr, $this->tenant);
    $this->getJson("/api/v1/talent-learning/surveys/{$survey['id']}/results")->assertOk()->assertJsonPath('data.results_visible', true)->assertJsonCount(3, 'data.questions');
});

test('results aggregate ratings, eNPS and free text and merge small departments', function (): void {
    $survey = tlSurvey();
    foreach ([[4, 10, 'Great team'], [5, 9, null], [3, 7, 'Slow tools'], [2, 3, null]] as [$rating, $enps, $text]) {
        tlRespond($survey, 'Sales', $rating, $enps, $text);
    }
    tlRespond($survey, 'Legal', 1, 0);
    tlRespond($survey, 'Support', 4, 8);
    tlRespond($survey, 'Support', 4, 8);
    tlRespond($survey, 'Support', 4, 8);
    tlAct($this->hr, $this->tenant);

    $data = $this->getJson("/api/v1/talent-learning/surveys/{$survey['id']}/results")->assertOk()->assertJsonPath('data.responses_count', 8)->json('data');
    [$rating, $enps, $text] = $data['questions'];

    $this->assertSame(3.38, $rating['average']);
    $this->assertSame(['1' => 1, '2' => 1, '3' => 1, '4' => 4, '5' => 1], $rating['distribution']);

    $this->assertSame(2, $enps['promoters']);
    $this->assertSame(4, $enps['passives']);
    $this->assertSame(2, $enps['detractors']);
    $this->assertEquals(0, $enps['enps']);

    $this->assertSame(2, $text['answers_count']);
    $this->assertEqualsCanonicalizing(['Great team', 'Slow tools'], $text['texts']);

    $segments = collect($data['segments'])->keyBy(fn (array $row): string => $row['is_other'] ? '*' : $row['segment']);
    $this->assertSame(['*', 'Sales'], $segments->keys()->sort()->values()->all());
    $this->assertSame(4, $segments['Sales']['responses_count']);
    $this->assertSame(4, $segments['*']['responses_count']);
    $this->assertFalse($data['hidden_segments']);
    $this->assertNull($segments->get('Legal'));
    $this->assertNull($segments->get('Support'));
    $salesRating = collect($rating['segments'])->firstWhere('segment', 'Sales');
    $this->assertSame(3.5, $salesRating['average']);
    $this->assertSame([], $text['segments'] ?? []);
});

test('a department that is too small is never derivable by subtraction', function (): void {
    $survey = tlSurvey();
    foreach ([1, 2, 3] as $rating) {
        tlRespond($survey, 'Sales', $rating, 5);
    }
    tlRespond($survey, 'Legal', 5, 9);
    tlRespond($survey, 'Legal', 5, 9);
    tlAct($this->hr, $this->tenant);

    $data = $this->getJson("/api/v1/talent-learning/surveys/{$survey['id']}/results")->assertOk()->json('data');

    $shown = collect($data['segments']);
    $this->assertSame(1, $shown->count());
    $this->assertTrue($shown->first()['is_other']);
    $this->assertSame(5, $shown->first()['responses_count']);
    $this->assertFalse($data['hidden_segments']);

    $other = tlSurvey(['title' => 'Second']);
    foreach ([1, 2, 3] as $rating) {
        tlRespond($other, 'Sales', $rating, 5);
    }
    tlRespond($other, 'Legal', 5, 9);
    tlAct($this->hr, $this->tenant);
    $second = $this->getJson("/api/v1/talent-learning/surveys/{$other['id']}/results")->assertOk()->json('data');
    $this->assertSame(1, count($second['segments']));
    $this->assertSame(4, $second['segments'][0]['responses_count']);
});

test('the dashboard reports the eNPS of the latest survey once the threshold is met', function (): void {
    $survey = tlSurvey();
    foreach ([10, 9, 8, 3] as $score) {
        tlRespond($survey, 'Sales', 4, $score);
    }
    tlAct($this->hr, $this->tenant);

    $this->getJson('/api/v1/talent-learning/dashboard')->assertOk()
        ->assertJsonPath('data.engagement.survey_id', $survey['id'])
        ->assertJsonPath('data.engagement.results_visible', true)
        ->assertJsonPath('data.engagement.enps.score', 25)
        ->assertJsonPath('data.engagement.enps.promoters', 2)
        ->assertJsonPath('data.engagement.enps.detractors', 1)
        ->assertJsonPath('data.surveys_open', 1);
});

test('closed and not yet open surveys take no answers and other companies cannot see a survey', function (): void {
    $survey = tlSurvey(['opens_on' => '2027-07-01']);
    $learner = tlLearner($this->tenant);
    tlAct($learner['user'], $this->tenant);
    $this->postJson("/api/v1/talent-learning/surveys/{$survey['id']}/responses", ['answers' => tlAnswers($survey, 3, 5)])->assertUnprocessable()->assertJsonValidationErrors('survey');

    tlAct($this->hr, $this->tenant);
    $this->postJson("/api/v1/talent-learning/surveys/{$survey['id']}/close")->assertOk();
    tlAct($learner['user'], $this->tenant);
    $this->postJson("/api/v1/talent-learning/surveys/{$survey['id']}/responses", ['answers' => tlAnswers($survey, 3, 5)])->assertUnprocessable();

    $other = tlTenant('Other');
    tlAct(tlMember($other, 'hr'), $other);
    $this->getJson("/api/v1/talent-learning/surveys/{$survey['id']}")->assertNotFound();
    $this->getJson("/api/v1/talent-learning/surveys/{$survey['id']}/results")->assertNotFound();
    $this->getJson('/api/v1/talent-learning/surveys')->assertOk()->assertJsonCount(0, 'data');
});
