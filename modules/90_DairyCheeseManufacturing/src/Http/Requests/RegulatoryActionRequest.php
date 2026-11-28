<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

/**
 * Marking a declaration as submitted: needs the regulatory ability and no input.
 */
final class RegulatoryActionRequest extends DairyFormRequest
{
    protected function ability(): string
    {
        return 'regulatory';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
