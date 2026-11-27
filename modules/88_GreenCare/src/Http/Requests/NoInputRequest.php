<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

/**
 * Actions that take no input (activate, suspend, refresh the weather).
 */
final class NoInputRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
