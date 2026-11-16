<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\TalentLearning\Contracts\HrDirectory;
use Modules\TalentLearning\Models\EngagementAnswer;
use Modules\TalentLearning\Models\EngagementParticipant;
use Modules\TalentLearning\Models\EngagementQuestion;
use Modules\TalentLearning\Models\EngagementResponse;
use Modules\TalentLearning\Models\EngagementSurvey;
use Modules\TalentLearning\Support\Rejection;

/**
 * Engagement surveys. An anonymous survey never stores who answered what: the participation is recorded apart, the
 * response carries a random id, the day of submission and the department only. Results (overall and per department)
 * are shown only for groups of at least min_group_size responses, and groups below the threshold are merged so that a
 * hidden group cannot be derived by subtraction. eNPS is promoters (9-10) minus detractors (0-6), in percent.
 */
final readonly class SurveyService
{
    public const string OtherSegment = '*';

    public function __construct(private HrDirectory $directory) {}

    /**
     * @param  array<string, mixed>  $data  survey fields and, optionally, the full list of questions
     */
    public function save(string $tenantId, array $data, ?EngagementSurvey $survey): EngagementSurvey
    {
        if ($survey !== null && $survey->status !== EngagementSurvey::Draft) {
            throw Rejection::on('status', 'survey.not_draft');
        }

        $questions = $data['questions'] ?? null;

        return DB::transaction(function () use ($tenantId, $data, $survey, $questions): EngagementSurvey {
            $fields = array_intersect_key($data, array_flip(['title', 'description', 'is_anonymous', 'min_group_size', 'opens_on', 'closes_on']));
            $survey = $survey === null ? EngagementSurvey::create([...$fields, 'tenant_id' => $tenantId, 'status' => EngagementSurvey::Draft]) : tap($survey)->update($fields);

            if (is_array($questions)) {
                EngagementQuestion::query()->where('survey_id', $survey->id)->get()->each->delete();

                foreach (array_values($questions) as $index => $question) {
                    EngagementQuestion::create([
                        'tenant_id' => $tenantId,
                        'survey_id' => $survey->id,
                        'position' => $index + 1,
                        'prompt' => $question['prompt'],
                        'question_type' => $question['question_type'],
                        'is_required' => $question['is_required'] ?? true,
                    ]);
                }
            }

            return $survey->refresh();
        });
    }

    public function delete(EngagementSurvey $survey): void
    {
        if ($survey->status !== EngagementSurvey::Draft) {
            throw Rejection::on('status', 'survey.not_draft');
        }

        DB::transaction(function () use ($survey): void {
            $survey->questions()->get()->each->delete();
            $survey->delete();
        });
    }

    public function open(EngagementSurvey $survey): EngagementSurvey
    {
        if ($survey->status !== EngagementSurvey::Draft) {
            throw Rejection::on('status', 'survey.not_draft');
        }

        if (! $survey->questions()->exists()) {
            throw Rejection::on('status', 'survey.no_questions');
        }

        $survey->update(['status' => EngagementSurvey::Open, 'opens_on' => $survey->opens_on ?? Carbon::today()]);

        return $survey->refresh();
    }

    public function close(EngagementSurvey $survey): EngagementSurvey
    {
        if ($survey->status !== EngagementSurvey::Open) {
            throw Rejection::on('status', 'survey.not_open');
        }

        $survey->update(['status' => EngagementSurvey::Closed]);

        return $survey->refresh();
    }

    /**
     * Submits the questionnaire of the employee behind the user. Returns nothing: the response cannot be traced back.
     *
     * @param  list<array{question_id: string, value?: int|string|null, text?: string|null}>  $answers
     */
    public function submit(EngagementSurvey $survey, string $userId, array $answers): void
    {
        $tenantId = (string) $survey->tenant_id;
        $today = Carbon::today();

        if ($survey->status !== EngagementSurvey::Open || ($survey->opens_on !== null && $survey->opens_on->greaterThan($today)) || ($survey->closes_on !== null && $survey->closes_on->lessThan($today))) {
            throw Rejection::on('survey', 'survey.not_open');
        }

        $employee = $this->directory->employeeOfUser($tenantId, $userId);

        if ($employee === null || ! $employee->isActive) {
            throw Rejection::on('survey', 'survey.no_employee');
        }

        $questions = $survey->questions()->get()->keyBy('id');
        $values = $this->validatedAnswers($questions, $answers);

        try {
            DB::transaction(function () use ($survey, $tenantId, $employee, $values, $today): void {
                EngagementParticipant::create(['tenant_id' => $tenantId, 'survey_id' => $survey->id, 'employee_id' => $employee->id, 'responded_on' => $today->toDateString()]);

                $response = EngagementResponse::create([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'survey_id' => $survey->id,
                    'respondent_employee_id' => $survey->is_anonymous ? null : $employee->id,
                    'segment' => $employee->departmentName,
                    'submitted_on' => $today->toDateString(),
                ]);

                foreach ($values as $questionId => $value) {
                    EngagementAnswer::create([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId,
                        'response_id' => $response->id,
                        'question_id' => $questionId,
                        'numeric_value' => $value['numeric'],
                        'text_value' => $value['text'],
                        'submitted_on' => $today->toDateString(),
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw Rejection::on('survey', 'survey.already_answered');
        }
    }

    public function hasAnswered(EngagementSurvey $survey, string $userId): bool
    {
        $employee = $this->directory->employeeOfUser((string) $survey->tenant_id, $userId);

        return $employee !== null && EngagementParticipant::query()->where('survey_id', $survey->id)->where('employee_id', $employee->id)->exists();
    }

    /**
     * Aggregated results with the anonymity threshold applied.
     *
     * @return array<string, mixed>
     */
    public function results(EngagementSurvey $survey): array
    {
        $questions = $survey->questions()->get();
        $responses = EngagementResponse::query()->where('survey_id', $survey->id)->get();
        $answers = EngagementAnswer::query()->whereIn('response_id', $responses->pluck('id'))->get()->groupBy('question_id');
        $invited = count($this->directory->activeEmployees((string) $survey->tenant_id));
        $threshold = $survey->is_anonymous ? $survey->min_group_size : 1;
        $visible = $responses->count() >= $threshold;

        $result = [
            'survey_id' => $survey->id,
            'status' => $survey->status,
            'is_anonymous' => $survey->is_anonymous,
            'min_group_size' => $survey->min_group_size,
            'invited_count' => $invited,
            'responses_count' => $responses->count(),
            'participation_rate_percent' => $invited > 0 ? round($responses->count() / $invited * 100, 1) : 0.0,
            'results_visible' => $visible,
            'questions' => [],
            'segments' => [],
            'hidden_segments' => false,
        ];

        if (! $visible) {
            return $result;
        }

        $responsesBySegment = $this->segments($responses, $threshold);
        $result['hidden_segments'] = $responsesBySegment['hidden'];

        foreach ($questions as $question) {
            $own = $answers->get($question->id, collect());
            $row = ['question_id' => $question->id, 'position' => $question->position, 'prompt' => $question->prompt, 'question_type' => $question->question_type, ...$this->aggregate($question, $own)];

            if ($question->question_type !== EngagementQuestion::Text) {
                $row['segments'] = [];

                foreach ($responsesBySegment['groups'] as $segment => $responseIds) {
                    $row['segments'][] = ['segment' => $segment === self::OtherSegment ? null : $segment, 'is_other' => $segment === self::OtherSegment, ...$this->aggregate($question, $own->whereIn('response_id', $responseIds), false)];
                }
            }

            $result['questions'][] = $row;
        }

        foreach ($responsesBySegment['groups'] as $segment => $responseIds) {
            $result['segments'][] = ['segment' => $segment === self::OtherSegment ? null : $segment, 'is_other' => $segment === self::OtherSegment, 'responses_count' => count($responseIds)];
        }

        return $result;
    }

    /**
     * The eNPS of a survey (first eNPS question), or null when there is none or the threshold is not reached.
     *
     * @return array<string, mixed>|null
     */
    public function enps(EngagementSurvey $survey): ?array
    {
        foreach ($this->results($survey)['questions'] as $question) {
            if ($question['question_type'] === EngagementQuestion::Enps) {
                return ['survey_id' => $survey->id, 'title' => $survey->title, 'score' => $question['enps'], 'promoters' => $question['promoters'], 'passives' => $question['passives'], 'detractors' => $question['detractors']];
            }
        }

        return null;
    }

    /**
     * Groups the responses by department. Departments under the threshold are merged into one "other" group; when that
     * group is still under the threshold the smallest visible departments join it, and when it stays under the threshold
     * it is dropped (the hidden flag is set).
     *
     * @param  Collection<int, EngagementResponse>  $responses
     * @return array{groups: array<string, list<string>>, hidden: bool}
     */
    private function segments(Collection $responses, int $threshold): array
    {
        $groups = [];

        foreach ($responses as $response) {
            $groups[(string) ($response->segment ?? '')][] = (string) $response->id;
        }

        uasort($groups, static fn (array $a, array $b): int => count($a) <=> count($b));
        $visible = [];
        $other = [];

        foreach ($groups as $segment => $ids) {
            if (count($ids) < $threshold || $segment === '') {
                $other = [...$other, ...$ids];
            } else {
                $visible[$segment] = $ids;
            }
        }

        while ($other !== [] && count($other) < $threshold && $visible !== []) {
            $smallest = array_key_first($visible);
            $other = [...$other, ...$visible[$smallest]];
            unset($visible[$smallest]);
        }

        $hidden = $other !== [] && count($other) < $threshold;

        if ($other !== [] && ! $hidden) {
            $visible[self::OtherSegment] = $other;
        }

        ksort($visible);

        return ['groups' => $visible, 'hidden' => $hidden];
    }

    /**
     * @param  Collection<int, EngagementAnswer>  $answers
     * @return array<string, mixed>
     */
    private function aggregate(EngagementQuestion $question, Collection $answers, bool $withTexts = true): array
    {
        $numbers = $answers->pluck('numeric_value')->filter(static fn (mixed $value): bool => $value !== null)->map(static fn (mixed $value): int => (int) $value)->values();

        if ($question->question_type === EngagementQuestion::Text) {
            return ['answers_count' => $answers->count(), 'texts' => $withTexts ? $answers->pluck('text_value')->filter()->shuffle()->values()->all() : []];
        }

        if ($question->question_type === EngagementQuestion::Rating) {
            $distribution = array_fill_keys(range(1, 5), 0);

            foreach ($numbers as $number) {
                $distribution[$number]++;
            }

            return ['answers_count' => $numbers->count(), 'average' => $numbers->isEmpty() ? null : round($numbers->avg(), 2), 'distribution' => $distribution];
        }

        $promoters = $numbers->filter(static fn (int $number): bool => $number >= 9)->count();
        $detractors = $numbers->filter(static fn (int $number): bool => $number <= 6)->count();
        $count = $numbers->count();

        return [
            'answers_count' => $count,
            'promoters' => $promoters,
            'passives' => $count - $promoters - $detractors,
            'detractors' => $detractors,
            'enps' => $count === 0 ? null : round(($promoters - $detractors) / $count * 100, 1),
        ];
    }

    /**
     * @param  Collection<string, EngagementQuestion>  $questions
     * @param  list<array<string, mixed>>  $answers
     * @return array<string, array{numeric: int|null, text: string|null}>
     */
    private function validatedAnswers(Collection $questions, array $answers): array
    {
        $values = [];

        foreach ($answers as $index => $answer) {
            $question = $questions->get((string) ($answer['question_id'] ?? ''));

            if ($question === null) {
                throw Rejection::on("answers.$index.question_id", 'survey.unknown_question');
            }

            if (isset($values[$question->id])) {
                throw Rejection::on("answers.$index.question_id", 'survey.duplicate_answer');
            }

            $value = $answer['value'] ?? null;
            $text = isset($answer['text']) ? trim((string) $answer['text']) : null;
            $numeric = null;

            if ($question->question_type === EngagementQuestion::Text) {
                $text = $text !== null && $text !== '' ? $text : null;

                if ($text === null && $question->is_required) {
                    throw Rejection::on("answers.$index.text", 'survey.answer_required');
                }
            } elseif ($value !== null && $value !== '') {
                [$min, $max] = $question->question_type === EngagementQuestion::Enps ? [0, 10] : [1, 5];

                if (! is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > $max) {
                    throw Rejection::on("answers.$index.value", 'survey.value_range', ['min' => $min, 'max' => $max]);
                }

                $numeric = (int) $value;
            } elseif ($question->is_required) {
                throw Rejection::on("answers.$index.value", 'survey.answer_required');
            }

            if ($numeric !== null || $text !== null) {
                $values[$question->id] = ['numeric' => $numeric, 'text' => $question->question_type === EngagementQuestion::Text ? $text : null];
            }
        }

        foreach ($questions as $question) {
            if ($question->is_required && ! isset($values[$question->id])) {
                throw Rejection::on('answers', 'survey.missing_required', ['question' => Str::limit($question->prompt, 60)]);
            }
        }

        return $values;
    }
}
