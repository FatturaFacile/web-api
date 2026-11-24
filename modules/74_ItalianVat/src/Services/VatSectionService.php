<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\VatSection;

/**
 * The sectionals (sezionali) of the VAT registers of a tenant. A tenant starts with the standard ones, created on first
 * use, and may add its own.
 */
final class VatSectionService
{
    public const string SALES = 'sales';

    public const string PURCHASES = 'purchases';

    public const string RECEIPTS = 'receipts';

    public const string GOODS = 'third_party_goods';

    public const array REGISTER_TYPES = [self::SALES, self::PURCHASES, self::RECEIPTS, self::GOODS];

    public const array DOCUMENT_KINDS = ['ordinary', 'deferred', 'credit_note', 'debit_note', 'self_invoice', 'reverse_charge'];

    /** @var list<array{register_type: string, code: string, name: string, document_kind: string|null}> */
    private const array DEFAULTS = [
        ['register_type' => self::SALES, 'code' => 'VEN', 'name' => 'Sales invoices', 'document_kind' => 'ordinary'],
        ['register_type' => self::SALES, 'code' => 'VEN-DIF', 'name' => 'Deferred sales invoices', 'document_kind' => 'deferred'],
        ['register_type' => self::SALES, 'code' => 'VEN-NC', 'name' => 'Sales credit and debit notes', 'document_kind' => 'credit_note'],
        ['register_type' => self::SALES, 'code' => 'VEN-AUT', 'name' => 'Self-invoices', 'document_kind' => 'self_invoice'],
        ['register_type' => self::PURCHASES, 'code' => 'ACQ', 'name' => 'Purchase invoices', 'document_kind' => 'ordinary'],
        ['register_type' => self::PURCHASES, 'code' => 'ACQ-NC', 'name' => 'Purchase credit and debit notes', 'document_kind' => 'credit_note'],
        ['register_type' => self::PURCHASES, 'code' => 'ACQ-RC', 'name' => 'Reverse charge purchases', 'document_kind' => 'reverse_charge'],
        ['register_type' => self::RECEIPTS, 'code' => 'COR', 'name' => 'Daily receipts', 'document_kind' => null],
        ['register_type' => self::GOODS, 'code' => 'MCT', 'name' => 'Goods on account of third parties', 'document_kind' => null],
    ];

    /**
     * @return Collection<int, VatSection>
     */
    public function list(string $tenantId, ?string $registerType = null): Collection
    {
        $this->ensureDefaults($tenantId);

        return VatSection::query()
            ->where('tenant_id', $tenantId)
            ->when($registerType !== null, fn ($query) => $query->where('register_type', $registerType))
            ->orderBy('register_type')
            ->orderBy('code')
            ->get();
    }

    public function ensureDefaults(string $tenantId): void
    {
        if (VatSection::query()->where('tenant_id', $tenantId)->withTrashed()->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $definition) {
            VatSection::create($definition + ['tenant_id' => $tenantId, 'is_active' => true]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): VatSection
    {
        $this->ensureDefaults($tenantId);

        return VatSection::create([
            'tenant_id' => $tenantId,
            'register_type' => $data['register_type'],
            'code' => strtoupper((string) $data['code']),
            'name' => $data['name'],
            'document_kind' => $data['document_kind'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Only the name and the active flag change: code, register and kind are part of the numbering that was already printed.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $tenantId, string $id, array $data): VatSection
    {
        $section = $this->find($tenantId, $id);
        $section->fill(array_intersect_key($data, array_flip(['name', 'is_active'])))->save();

        return $section;
    }

    public function find(string $tenantId, string $id): VatSection
    {
        return VatSection::query()->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw ItalianVatException::notFound('section_not_found');
    }
}
