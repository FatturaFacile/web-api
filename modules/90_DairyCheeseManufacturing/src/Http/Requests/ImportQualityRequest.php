<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class ImportQualityRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) config('dairy_cheese.import_max_kb', 5120), 'mimes:csv,txt,xml'],
            'format' => ['required', 'in:suisselab,tsm,aia,aral,generic_csv'],
            'commit' => ['nullable', 'boolean'],
        ];
    }
}
