<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Contracts\Models\ContractClause;
use Modules\Contracts\Models\ContractTemplate;

/**
 * Saves contract templates with their ordered clauses. An active template only holds approved clauses.
 */
final class ContractTemplateService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(?ContractTemplate $template, array $data): ContractTemplate
    {
        $status = (string) ($data['status'] ?? $template?->status ?? 'draft');
        $clauses = $data['clauses'] ?? null;

        if ($status === 'active' && is_array($clauses)) {
            $unapproved = ContractClause::query()->whereIn('id', array_column($clauses, 'clause_id'))->where('status', '!=', 'approved')->pluck('code')->all();

            if ($unapproved !== []) {
                throw ValidationException::withMessages(['clauses' => __('contracts.template.unapproved_clauses', ['codes' => implode(', ', $unapproved)])]);
            }
        }

        return DB::transaction(function () use ($template, $data, $clauses): ContractTemplate {
            $attributes = array_intersect_key($data, array_flip(['name', 'contract_kind', 'status', 'variables']));
            $template = $template === null ? ContractTemplate::query()->create($attributes) : tap($template)->update($attributes);

            if (is_array($clauses)) {
                $template->templateClauses()->delete();

                foreach (array_values($clauses) as $index => $entry) {
                    $template->templateClauses()->create(['clause_id' => $entry['clause_id'], 'position' => $index + 1, 'is_optional' => (bool) ($entry['is_optional'] ?? false)]);
                }
            }

            return $template->load('templateClauses.clause');
        });
    }
}
