<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use Illuminate\Validation\ValidationException;
use Modules\Contracts\Models\ContractTemplate;

/**
 * Assembles the text of a contract from a template: the clauses in position order (optional ones only when asked for,
 * a fallback wording when one is chosen) with the {{variable}} placeholders replaced by the given values.
 */
final class TemplateAssembler
{
    public function __construct(private readonly ClauseLibrary $library) {}

    /**
     * @param  array<string, scalar|null>  $values
     * @param  list<string>  $includeOptional  Template clause ids to keep although optional
     * @param  array<string, int>  $fallbackRanks  Fallback rank per clause id
     */
    public function assemble(ContractTemplate $template, array $values, array $includeOptional = [], array $fallbackRanks = []): string
    {
        if ($template->status !== 'active') {
            throw ValidationException::withMessages(['template_id' => __('contracts.template.inactive')]);
        }

        $resolved = $this->resolveValues($template, $values);
        $sections = [];

        foreach ($template->templateClauses()->with('clause')->get() as $entry) {
            if ($entry->is_optional && ! in_array((string) $entry->getKey(), $includeOptional, true)) {
                continue;
            }

            $clause = $entry->clause;

            if ($clause === null || $clause->status !== 'approved') {
                throw ValidationException::withMessages(['template_id' => __('contracts.template.clause_not_approved', ['clause' => $clause?->code ?? '-'])]);
            }

            $sections[] = trim($clause->title)."\n".$this->library->resolveBody($clause, $fallbackRanks[$clause->getKey()] ?? null);
        }

        $text = $this->substitute(implode("\n\n", $sections), $resolved);

        if (preg_match_all('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', $text, $left) > 0) {
            throw ValidationException::withMessages(['variables' => __('contracts.template.undeclared_placeholders', ['names' => implode(', ', array_unique($left[1]))])]);
        }

        return $text;
    }

    /**
     * Values for every declared variable: given, else default; a required one without a value is rejected.
     *
     * @param  array<string, scalar|null>  $values
     * @return array<string, string>
     */
    private function resolveValues(ContractTemplate $template, array $values): array
    {
        $resolved = [];
        $missing = [];

        foreach ($template->variables ?? [] as $variable) {
            $key = (string) $variable['key'];
            $value = $values[$key] ?? ($variable['default'] ?? null);

            if ($value === null || $value === '') {
                if (($variable['required'] ?? false) === true) {
                    $missing[] = $key;
                }

                continue;
            }

            $resolved[$key] = (string) $value;
        }

        if ($missing !== []) {
            throw ValidationException::withMessages(['variables' => __('contracts.template.missing_variables', ['names' => implode(', ', $missing)])]);
        }

        return $resolved;
    }

    /**
     * @param  array<string, string>  $resolved
     */
    private function substitute(string $text, array $resolved): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/',
            static fn (array $match): string => $resolved[$match[1]] ?? $match[0],
            $text,
        );
    }
}
