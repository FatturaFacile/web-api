<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The PDF contract of the convention (field "file") or the branding logo of its landing page (route logo).
 */
final class ContractFileRequest extends FormRequest
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
            'file' => ['required', 'file', $this->routeIs('corporate-conventions.conventions.logo') ? 'mimes:png,jpg,jpeg,svg,webp' : 'mimes:pdf', 'max:'.($this->routeIs('corporate-conventions.conventions.logo') ? 2048 : 10240)],
        ];
    }
}
