<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\ThirdPartyGoodsMovement;
use Modules\ItalianVat\Models\VatSection;

/**
 * The register of goods held, processed or sold on account of third parties (art. 39 DPR 633/1972): every movement gets a
 * progressive protocol number, goods cannot leave the custody of the company beyond what was received, and a definitive
 * print locks the registered movements like in the other registers.
 */
final class ThirdPartyGoodsService
{
    public const array TYPES = [
        ThirdPartyGoodsMovement::RECEIVED,
        ThirdPartyGoodsMovement::RETURNED,
        ThirdPartyGoodsMovement::PROCESSED,
        ThirdPartyGoodsMovement::SOLD,
    ];

    public function __construct(
        private readonly VatRegisterService $registers,
        private readonly VatSectionService $sections,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(string $tenantId, ?string $userId, array $data): ThirdPartyGoodsMovement
    {
        $section = $this->goodsSection($tenantId, $data['section_id'] ?? null);
        $date = CarbonImmutable::parse((string) $data['movement_date'])->startOfDay();
        $quantity = (string) $data['quantity'];
        $type = (string) $data['movement_type'];

        if (! in_array($type, self::TYPES, true)) {
            throw ItalianVatException::invalid('movement_type_invalid', ['type' => $type]);
        }

        if (! Decimal::isPositive($quantity)) {
            throw ItalianVatException::invalid('quantity_must_be_positive');
        }

        return DB::transaction(function () use ($tenantId, $userId, $data, $section, $date, $quantity, $type): ThirdPartyGoodsMovement {
            $this->registers->lockSection($section);
            $this->registers->assertPeriodOpen($section, $date);

            if (in_array($type, ThirdPartyGoodsMovement::OUTBOUND, true)) {
                $held = $this->held($tenantId, (string) $data['contact_id'], (string) $data['description'], (string) ($data['unit'] ?? 'pz'));

                if (Decimal::compare($quantity, $held) > 0) {
                    throw ItalianVatException::invalid('goods_insufficient_balance', ['held' => Decimal::round2($held), 'requested' => Decimal::round2($quantity)]);
                }
            }

            return ThirdPartyGoodsMovement::create([
                'tenant_id' => $tenantId,
                'section_id' => $section->id,
                'year' => $date->year,
                'protocol_number' => $this->registers->nextProtocol($section, $date->year),
                'movement_date' => $date->toDateString(),
                'movement_type' => $type,
                'contact_id' => $data['contact_id'],
                'description' => $data['description'],
                'unit' => $data['unit'] ?? 'pz',
                'quantity' => $quantity,
                'value' => Decimal::round2((string) ($data['value'] ?? '0')),
                'document_number' => $data['document_number'] ?? null,
                'document_date' => isset($data['document_date']) ? CarbonImmutable::parse((string) $data['document_date'])->toDateString() : null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Quantity and value of the goods of each third party still in custody on a date, per description and unit.
     *
     * @return list<array{contact_id: string, description: string, unit: string, held_quantity: string, held_value: string}>
     */
    public function balances(string $tenantId, ?string $contactId = null, ?CarbonImmutable $asOf = null): array
    {
        $movements = ThirdPartyGoodsMovement::query()
            ->where('tenant_id', $tenantId)
            ->when($contactId !== null, fn ($query) => $query->where('contact_id', $contactId))
            ->when($asOf !== null, fn ($query) => $query->whereDate('movement_date', '<=', $asOf->toDateString()))
            ->orderBy('movement_date')
            ->orderBy('protocol_number')
            ->get();

        $balances = [];

        foreach ($movements as $movement) {
            $key = $movement->contact_id.'|'.$movement->description.'|'.$movement->unit;
            $balances[$key] ??= ['contact_id' => (string) $movement->contact_id, 'description' => $movement->description, 'unit' => $movement->unit, 'held_quantity' => '0', 'held_value' => '0'];
            $inbound = in_array($movement->movement_type, ThirdPartyGoodsMovement::INBOUND, true);
            $apply = $inbound ? Decimal::add(...) : Decimal::sub(...);
            $balances[$key]['held_quantity'] = $apply($balances[$key]['held_quantity'], (string) $movement->quantity);
            $balances[$key]['held_value'] = $apply($balances[$key]['held_value'], (string) $movement->value);
        }

        return array_values(array_map(static fn (array $row): array => [
            'contact_id' => $row['contact_id'],
            'description' => $row['description'],
            'unit' => $row['unit'],
            'held_quantity' => Decimal::round2($row['held_quantity']),
            'held_value' => Decimal::round2($row['held_value']),
        ], $balances));
    }

    /**
     * @return list<ThirdPartyGoodsMovement>
     */
    public function movements(string $tenantId, ?string $contactId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ThirdPartyGoodsMovement::query()
            ->where('tenant_id', $tenantId)
            ->when($contactId !== null, fn ($query) => $query->where('contact_id', $contactId))
            ->whereBetween('movement_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('year')
            ->orderBy('protocol_number')
            ->get()
            ->all();
    }

    private function held(string $tenantId, string $contactId, string $description, string $unit): string
    {
        $rows = ThirdPartyGoodsMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('description', $description)
            ->where('unit', $unit)
            ->get(['movement_type', 'quantity']);

        $held = '0';

        foreach ($rows as $row) {
            $held = in_array($row->movement_type, ThirdPartyGoodsMovement::INBOUND, true)
                ? Decimal::add($held, (string) $row->quantity)
                : Decimal::sub($held, (string) $row->quantity);
        }

        return $held;
    }

    private function goodsSection(string $tenantId, mixed $sectionId): VatSection
    {
        if (is_string($sectionId) && $sectionId !== '') {
            $section = $this->sections->find($tenantId, $sectionId);

            if ($section->register_type !== VatSectionService::GOODS) {
                throw ItalianVatException::invalid('section_not_goods_register');
            }

            return $section;
        }

        return $this->sections->list($tenantId, VatSectionService::GOODS)->first()
            ?? throw ItalianVatException::notFound('section_not_found');
    }
}
