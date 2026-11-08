<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SDI\Enums\TipoDocumento;
use Throwable;

/**
 * The period (at most one year) and optional document type of the sales register.
 */
final class SalesRegisterRequest extends FormRequest
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
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:'.$this->latestEnd()],
            'document_type' => ['nullable', Rule::in(TipoDocumento::values())],
        ];
    }

    /**
     * One year after the start of the period, so a single request never scans an unbounded history.
     */
    private function latestEnd(): string
    {
        try {
            return CarbonImmutable::parse((string) $this->input('from'))->addYear()->toDateString();
        } catch (Throwable) {
            return '9999-12-31';
        }
    }
}
