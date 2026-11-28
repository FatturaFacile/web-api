<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class GenerateRegulatoryRequest extends DairyFormRequest
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
        return [
            'authority' => ['required', 'in:dbmilch,sian'],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'format' => ['nullable', 'in:xml,csv'],
        ];
    }
}
