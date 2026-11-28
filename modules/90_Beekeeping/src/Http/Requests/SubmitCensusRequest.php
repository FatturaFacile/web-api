<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

final class SubmitCensusRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'submission_reference' => ['nullable', 'string', 'max:80'],
        ];
    }
}
