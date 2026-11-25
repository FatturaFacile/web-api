<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What-if parameters of the crisis indicator simulator (percent changes and absolute overrides).
 */
final class WhatIfRequest extends FormRequest
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
            'what_if' => ['sometimes', 'array'],
            'what_if.ebitda_change_percent' => ['sometimes', 'nullable', 'numeric', 'min:-100', 'max:1000'],
            'what_if.revenue_change_percent' => ['sometimes', 'nullable', 'numeric', 'min:-100', 'max:1000'],
            'what_if.debt_service_change_percent' => ['sometimes', 'nullable', 'numeric', 'min:-100', 'max:1000'],
            'what_if.extra_liquidity' => ['sometimes', 'nullable', 'numeric'],
            'what_if.expected_cash_flow_6m' => ['sometimes', 'nullable', 'numeric'],
            'what_if.debt_service_6m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'what_if.opening_liquidity' => ['sometimes', 'nullable', 'numeric'],
            'persist' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function whatIf(): array
    {
        $values = [];

        foreach ((array) $this->validated('what_if', []) as $key => $value) {
            if ($value !== null && $value !== '') {
                $values[(string) $key] = (string) $value;
            }
        }

        return $values;
    }
}
