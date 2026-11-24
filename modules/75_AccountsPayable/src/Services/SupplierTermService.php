<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use Illuminate\Support\Collection;
use Modules\AccountsPayable\DTOs\SkontoTerms;
use Modules\AccountsPayable\Exceptions\AccountsPayableException;
use Modules\AccountsPayable\Models\ApSupplierTerm;

/**
 * The cash-discount agreements with the suppliers. The terms can be given as a percentage and days, or as the text of the
 * payment clause (`30 gg data fattura, 2% skonto entro 10 gg`), which is read by SkontoClauseParser.
 */
final class SupplierTermService
{
    public function __construct(private readonly SkontoClauseParser $parser) {}

    /**
     * @return Collection<int, ApSupplierTerm>
     */
    public function list(string $tenantId, ?string $supplierId = null): Collection
    {
        return ApSupplierTerm::query()
            ->where('tenant_id', $tenantId)
            ->when($supplierId !== null, static fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('supplier_id')
            ->orderByDesc('valid_from')
            ->get();
    }

    /**
     * @param  array{supplier_id: string, clause?: string|null, skonto_percentage?: string|float|int|null, skonto_days?: int|null, net_days?: int|null, reference?: string|null, valid_from?: string|null, valid_to?: string|null, is_active?: bool|null, notes?: string|null}  $data
     *
     * @throws AccountsPayableException when neither the clause nor the percentage and days give a discount
     */
    public function create(string $tenantId, array $data): ApSupplierTerm
    {
        return ApSupplierTerm::query()->create(['tenant_id' => $tenantId, 'supplier_id' => $data['supplier_id']] + $this->attributes($data));
    }

    /**
     * @param  array{clause?: string|null, skonto_percentage?: string|float|int|null, skonto_days?: int|null, net_days?: int|null, reference?: string|null, valid_from?: string|null, valid_to?: string|null, is_active?: bool|null, notes?: string|null}  $data
     *
     * @throws AccountsPayableException
     */
    public function update(string $tenantId, string $id, array $data): ApSupplierTerm
    {
        $term = $this->find($tenantId, $id);
        $term->update($this->attributes($data, $term));

        return $term->refresh();
    }

    /**
     * @throws AccountsPayableException
     */
    public function delete(string $tenantId, string $id): void
    {
        $this->find($tenantId, $id)->delete();
    }

    /**
     * @throws AccountsPayableException
     */
    public function find(string $tenantId, string $id): ApSupplierTerm
    {
        return ApSupplierTerm::query()->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw AccountsPayableException::notFound('supplier_term_not_found');
    }

    /**
     * What a payment clause means, without saving anything.
     */
    public function parse(string $clause): ?SkontoTerms
    {
        return $this->parser->parse($clause);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws AccountsPayableException
     */
    private function attributes(array $data, ?ApSupplierTerm $current = null): array
    {
        $clause = (string) ($data['clause'] ?? '');
        $parsed = $clause !== '' ? $this->parser->parse($clause, isset($data['reference']) ? (string) $data['reference'] : null) : null;

        if ($clause !== '' && $parsed === null && ! isset($data['skonto_percentage'])) {
            throw AccountsPayableException::invalid('clause_not_understood');
        }

        $percentage = isset($data['skonto_percentage']) ? (string) $data['skonto_percentage'] : null;
        $days = isset($data['skonto_days']) ? (int) $data['skonto_days'] : null;
        $netDays = isset($data['net_days']) ? (int) $data['net_days'] : null;

        if ($parsed !== null) {
            $percentage ??= $parsed->percentage;
            $days ??= $parsed->days;
            $netDays ??= $parsed->netDays;
        }

        if ($current !== null) {
            $percentage ??= (string) $current->skonto_percentage;
            $days ??= (int) $current->skonto_days;
            $netDays ??= $current->net_days;
        }

        if ($percentage === null || $days === null || bccomp($percentage, '0', 2) <= 0 || bccomp($percentage, '100', 2) > 0) {
            throw AccountsPayableException::invalid('skonto_terms_invalid');
        }

        $attributes = [
            'skonto_percentage' => bcadd($percentage, '0', 2),
            'skonto_days' => $days,
            'net_days' => $netDays,
        ];
        foreach (['clause', 'reference', 'valid_from', 'valid_to', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        return $attributes;
    }
}
