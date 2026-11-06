<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Exceptions\VatSettlementException;
use Modules\GeneralLedger\Models\VatRegister;
use Modules\GeneralLedger\Models\VatRegisterEntry;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;
use Modules\GeneralLedger\Services\Tax\VatLedgerReader;
use Modules\GeneralLedger\Services\Tax\VatLedgerSnapshot;
use Modules\GeneralLedger\Services\Tax\VatRegisterLine;

/**
 * The sales and purchases VAT registers. Their lines are read from the ledger; the protocol numbers are assigned, in
 * order of registration, when the settlement of the period is posted, and do not change afterwards.
 */
final class VatRegisterService
{
    public const string SALES = 'sales';

    public const string PURCHASES = 'purchases';

    private const array REGISTERS = [
        self::SALES => ['code' => 'REG_VEN_1', 'name' => 'Registro IVA vendite'],
        self::PURCHASES => ['code' => 'REG_ACQ_1', 'name' => 'Registro IVA acquisti'],
    ];

    public function __construct(
        private readonly VatLedgerReader $ledgerReader,
        private readonly VatAccountResolver $accountResolver,
    ) {}

    /**
     * @return array{lines: list<array<string, mixed>>, totals: array{taxable: string, tax: string}}
     */
    public function linesOf(string $tenantId, string $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $country = strtoupper((string) Tenant::query()->whereKey($tenantId)->value('country'));
        $snapshot = $this->ledgerReader->read($tenantId, $country, $from, $to, $this->accountResolver->accountsFor($tenantId, $country));
        $lines = $type === self::SALES ? $snapshot->saleLines : $snapshot->purchaseLines;

        $entryIds = array_map(static fn (VatRegisterLine $line): string => $line->journalEntryId, $lines);
        $protocols = VatRegisterEntry::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereIn('journal_entry_id', $entryIds)
            ->whereIn('vat_register_id', VatRegister::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('register_type', $type)
                ->select('id'))
            ->pluck('protocol_number', 'journal_entry_id');
        $partners = $this->partnerNames($tenantId, $lines);

        $rows = [];
        $taxable = '0';
        $tax = '0';

        foreach ($lines as $line) {
            $rows[] = [
                'protocol_number' => $protocols[$line->journalEntryId] ?? null,
                'posting_date' => $line->postingDate,
                'document_date' => $line->documentDate,
                'document_number' => $line->documentNumber ?? $line->entryNumber,
                'document_type' => $line->sourceDocumentType,
                'partner_name' => $line->partnerId !== null ? ($partners[$line->partnerId] ?? null) : null,
                'taxable_amount' => $line->taxable,
                'vat_rate' => $line->rate,
                'vat_amount' => $line->tax,
                'vat_treatment' => $line->treatment,
            ];
            $taxable = Decimal::add($taxable, $line->taxable);
            $tax = Decimal::add($tax, $line->tax);
        }

        return ['lines' => $rows, 'totals' => ['taxable' => Decimal::round2($taxable), 'tax' => Decimal::round2($tax)]];
    }

    /**
     * Assigns the next protocol numbers to the documents of a posted period.
     */
    public function assignProtocols(string $tenantId, ?string $companyId, string $vatSettlementId, VatLedgerSnapshot $snapshot): void
    {
        foreach ([self::SALES => $snapshot->saleLines, self::PURCHASES => $snapshot->purchaseLines] as $type => $lines) {
            $seen = [];

            foreach ($lines as $line) {
                if (isset($seen[$line->journalEntryId])) {
                    continue;
                }

                $seen[$line->journalEntryId] = true;
                $this->assignProtocol($tenantId, $companyId, $type, $line, $vatSettlementId);
            }
        }
    }

    private function assignProtocol(string $tenantId, ?string $companyId, string $type, VatRegisterLine $line, string $vatSettlementId): void
    {
        $register = $this->registerOf($tenantId, $companyId, $type, (int) substr($line->postingDate, 0, 4));

        $alreadyAssigned = VatRegisterEntry::withoutGlobalScope(TenantScope::class)
            ->where('vat_register_id', $register->id)
            ->where('journal_entry_id', $line->journalEntryId)
            ->exists();

        if ($alreadyAssigned) {
            return;
        }

        $protocolNumber = (int) $register->last_protocol_number + 1;
        $register->update(['last_protocol_number' => $protocolNumber]);

        VatRegisterEntry::create([
            'tenant_id' => $tenantId,
            'vat_register_id' => $register->id,
            'journal_entry_id' => $line->journalEntryId,
            'vat_settlement_id' => $vatSettlementId,
            'protocol_number' => $protocolNumber,
        ]);
    }

    private function registerOf(string $tenantId, ?string $companyId, string $type, int $year): VatRegister
    {
        if (! isset(self::REGISTERS[$type])) {
            throw VatSettlementException::invalid("Unknown VAT register [{$type}].");
        }

        $definition = self::REGISTERS[$type];

        $register = VatRegister::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('code', $definition['code'])
            ->where('progressive_protocol_year', $year)
            ->lockForUpdate()
            ->first();

        return $register ?? VatRegister::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'code' => $definition['code'],
            'name' => $definition['name'],
            'register_type' => $type,
            'progressive_protocol_year' => $year,
            'last_protocol_number' => 0,
        ]);
    }

    /**
     * @param  list<VatRegisterLine>  $lines
     * @return array<string, string>
     */
    private function partnerNames(string $tenantId, array $lines): array
    {
        $partnerIds = array_values(array_unique(array_filter(array_map(static fn (VatRegisterLine $line): ?string => $line->partnerId, $lines))));

        if ($partnerIds === []) {
            return [];
        }

        return DB::table('contacts')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $partnerIds)
            ->get(['id', 'company_name', 'first_name', 'last_name'])
            ->mapWithKeys(static fn (object $contact): array => [
                (string) $contact->id => $contact->company_name ?: trim($contact->first_name.' '.$contact->last_name),
            ])
            ->all();
    }
}
