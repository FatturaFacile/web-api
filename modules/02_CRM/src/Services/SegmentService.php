<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Modules\CRM\Models\Consent;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Segment;

/**
 * Turns a saved segment (a list of whitelisted field conditions) into a tenant-scoped query over contacts or leads.
 * Only whitelisted columns and operators are accepted, so a stored filter can never inject SQL or reach another
 * table. The optional `consent` condition keeps only subjects with a current opt-in for the given channel and purpose.
 */
final class SegmentService
{
    /**
     * Filterable columns per entity.
     */
    public const array Fields = [
        'contacts' => ['type', 'country', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'vat_number', 'fiscal_code', 'document_language', 'created_at'],
        'leads' => ['status', 'rating', 'score', 'country', 'company_name', 'title', 'email', 'phone', 'estimated_budget', 'campaign_id', 'assigned_user_id', 'created_at'],
    ];

    public const array Operators = ['eq', 'neq', 'contains', 'starts_with', 'in', 'gte', 'lte', 'is_null', 'not_null'];

    /**
     * Validates a filter definition, so invalid segments are refused when saved and not when a campaign is sent.
     *
     * @param  array<int|string, mixed>  $filters
     *
     * @throws InvalidArgumentException
     */
    public function assertValid(string $entity, array $filters): void
    {
        if (! isset(self::Fields[$entity])) {
            throw new InvalidArgumentException("Unknown segment entity [{$entity}].");
        }

        foreach ($filters as $index => $filter) {
            if (! is_array($filter)) {
                throw new InvalidArgumentException("Filter #{$index} must be an object.");
            }

            if (($filter['field'] ?? null) === 'consent') {
                if (! in_array($filter['channel'] ?? 'email', Consent::Channels, true) || ! in_array($filter['purpose'] ?? 'marketing', Consent::Purposes, true)) {
                    throw new InvalidArgumentException("Filter #{$index}: unknown consent channel or purpose.");
                }

                continue;
            }

            if (! in_array($filter['field'] ?? null, self::Fields[$entity], true)) {
                throw new InvalidArgumentException("Filter #{$index}: field [".($filter['field'] ?? '').'] cannot be filtered.');
            }

            if (! in_array($filter['operator'] ?? null, self::Operators, true)) {
                throw new InvalidArgumentException("Filter #{$index}: operator [".($filter['operator'] ?? '').'] is not supported.');
            }
        }
    }

    /**
     * The members of a segment as a query on the active tenant.
     *
     * @return Builder<Contact>|Builder<Lead>
     */
    public function query(Segment $segment): Builder
    {
        $filters = is_array($segment->filters) ? $segment->filters : [];
        $this->assertValid((string) $segment->entity, $filters);

        $query = $segment->entity === 'leads'
            ? Lead::query()->where('tenant_id', $segment->tenant_id)
            : Contact::query()->where('tenant_id', $segment->tenant_id);

        foreach ($filters as $filter) {
            $this->apply($query, (string) $segment->entity, $filter);
        }

        return $query;
    }

    /**
     * @param  Builder<Contact>|Builder<Lead>  $query
     * @param  array<string, mixed>  $filter
     */
    private function apply(Builder $query, string $entity, array $filter): void
    {
        if ($filter['field'] === 'consent') {
            $column = $entity === 'leads' ? 'lead_id' : 'contact_id';
            $table = $entity === 'leads' ? 'crm_leads' : 'contacts';
            $channel = (string) ($filter['channel'] ?? 'email');
            $purpose = (string) ($filter['purpose'] ?? 'marketing');

            // Latest record per subject must be a grant (opt-in, not withdrawn).
            $query->whereRaw(
                "(SELECT c.status FROM crm_consents c WHERE c.{$column} = {$table}.id AND c.channel = ? AND c.purpose = ? AND c.deleted_at IS NULL ORDER BY c.captured_at DESC, c.created_at DESC, c.id DESC LIMIT 1) = ?",
                [$channel, $purpose, Consent::Granted]
            );

            return;
        }

        $field = (string) $filter['field'];
        $value = $filter['value'] ?? null;

        match ($filter['operator']) {
            'eq' => $query->where($field, $value),
            'neq' => $query->where(fn (Builder $inner) => $inner->where($field, '<>', $value)->orWhereNull($field)),
            'contains' => $query->whereRaw("LOWER({$query->getQuery()->getGrammar()->wrap($field)}) LIKE ? ESCAPE '\\'", ['%'.mb_strtolower(addcslashes((string) $value, '%_\\')).'%']),
            'starts_with' => $query->whereRaw("LOWER({$query->getQuery()->getGrammar()->wrap($field)}) LIKE ? ESCAPE '\\'", [mb_strtolower(addcslashes((string) $value, '%_\\')).'%']),
            'in' => $query->whereIn($field, is_array($value) ? $value : array_map('trim', explode(',', (string) $value))),
            'gte' => $query->where($field, '>=', $value),
            'lte' => $query->where($field, '<=', $value),
            'is_null' => $query->whereNull($field),
            'not_null' => $query->whereNotNull($field),
            default => null,
        };
    }
}
