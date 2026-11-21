<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportCedoliniRequest extends FormRequest
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
        return ['bundle_pdf' => ['required', 'file', 'mimes:pdf', 'max:51200']];
    }
}
