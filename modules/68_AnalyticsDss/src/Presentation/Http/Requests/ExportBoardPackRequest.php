<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Presentation\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Filter, file format and language of the board pack.
 */
final class ExportBoardPackRequest extends DssFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['nullable', Rule::in(['pdf', 'xlsx'])],
            'locale' => ['nullable', Rule::in(['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'])],
        ];
    }
}
