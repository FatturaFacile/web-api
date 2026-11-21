<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A camt.054 notification sent as raw XML text. */
final class ParseCamt054Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'xml' => ['required', 'string', 'max:5000000'],
        ];
    }
}
