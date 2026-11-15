<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * Progress of an enrolment, in percent (it can only grow).
 */
final class ProgressRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['progress_percent' => ['required', 'integer', 'between:0,100']];
    }
}
