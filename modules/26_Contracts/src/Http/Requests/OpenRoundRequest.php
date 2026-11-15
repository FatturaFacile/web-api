<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

/**
 * Opens a negotiation round.
 */
final class OpenRoundRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['summary' => ['nullable', 'string', 'max:2000']];
    }
}
