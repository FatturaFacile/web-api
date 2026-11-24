<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * The decision on a merit proposal: the note is optional to approve and required to reject.
 */
final class DecisionRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['decision_note' => [$this->routeIs('*.reject') ? 'required' : 'nullable', 'string', 'max:2000']];
    }
}
