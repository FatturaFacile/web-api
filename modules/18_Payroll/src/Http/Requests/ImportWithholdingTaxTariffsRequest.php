<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportWithholdingTaxTariffsRequest extends FormRequest
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
            'file' => ['required_without:content', 'file', 'max:102400'],
            'content' => ['required_without:file', 'string'],
        ];
    }

    public function tariffContent(): string
    {
        $file = $this->file('file');

        return $file !== null ? (string) file_get_contents($file->getRealPath()) : (string) $this->validated('content');
    }
}
