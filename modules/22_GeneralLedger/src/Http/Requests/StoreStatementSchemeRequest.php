<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreStatementSchemeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
            'statement_type' => ['required', 'string', 'in:income_statement,balance_sheet'],
            'description' => ['nullable', 'string', 'max:5000'],
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.code' => ['required', 'string', 'max:40', 'distinct'],
            'rows.*.label' => ['required', 'string', 'max:255'],
            'rows.*.row_type' => ['required', 'string', 'in:heading,accounts,subtotal'],
            'rows.*.sign' => ['nullable', 'integer', 'in:1,-1'],
            'rows.*.account_prefixes' => ['required_if:rows.*.row_type,accounts', 'array'],
            'rows.*.account_prefixes.*' => ['string', 'max:50'],
            'rows.*.formula' => ['required_if:rows.*.row_type,subtotal', 'array'],
            'rows.*.formula.*.row' => ['required', 'string', 'max:40'],
            'rows.*.formula.*.sign' => ['nullable', 'integer', 'in:1,-1'],
        ];
    }

    /**
     * A subtotal may only read rows above it, which rules out circular formulas.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $seen = [];

            foreach ((array) $this->input('rows', []) as $index => $row) {
                foreach ((array) ($row['formula'] ?? []) as $operand) {
                    if (! in_array($operand['row'] ?? null, $seen, true)) {
                        $validator->errors()->add("rows.{$index}.formula", 'A subtotal can only use rows above it.');
                    }
                }

                $seen[] = $row['code'] ?? null;
            }
        }];
    }
}
