<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Fichier des Ecritures Comptables (FEC) of a fiscal year, as required by the French tax authority (article L47 A-I
 * of the Livre des procedures fiscales): the 18 mandatory columns, tab separated, dates as YYYYMMDD, decimal comma,
 * one row per posted line in order of journal and entry. The file is named SirenFECYYYYMMDD after the year end.
 */
final class FecExporter
{
    /** @var list<string> */
    public const array Columns = [
        'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib', 'CompAuxNum', 'CompAuxLib',
        'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit', 'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
    ];

    public function __construct(private JournalLetteringService $letterings) {}

    public function fileName(string $tenantId, FiscalYear $fiscalYear): string
    {
        return $this->siren($tenantId).'FEC'.Carbon::parse($fiscalYear->end_date)->format('Ymd').'.txt';
    }

    /**
     * @return array{content: string, rows: int}
     */
    public function build(string $tenantId, FiscalYear $fiscalYear): array
    {
        $entries = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->whereNotNull('journal_number')
            ->whereIn('status', ['posted', 'reversed'])
            ->with(['lines' => fn ($query) => $query->withoutGlobalScopes()->with(['account' => fn ($account) => $account->withoutGlobalScopes()])->orderBy('line_number')])
            ->orderBy('journal_type')
            ->orderBy('journal_number')
            ->get();

        $partners = $this->partners($tenantId, $entries);
        $letterings = $this->letterings->forLines($tenantId, $entries->flatMap(static fn (JournalEntry $entry) => $entry->lines->pluck('id'))->map(strval(...))->all());
        $lines = [implode("\t", self::Columns)];
        $count = 0;

        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                $lines[] = implode("\t", $this->row($entry, $line, $partners, $letterings[(string) $line->id] ?? null));
                $count++;
            }
        }

        return ['content' => implode("\r\n", $lines)."\r\n", 'rows' => $count];
    }

    /**
     * @param  array<string, array{number: string, name: string}>  $partners
     * @param  array{code: string, date: string}|null  $lettering
     * @return list<string>
     */
    private function row(JournalEntry $entry, JournalLine $line, array $partners, ?array $lettering): array
    {
        $partner = $line->partner_id !== null ? ($partners[(string) $line->partner_id] ?? null) : null;
        $debit = (string) ($line->functional_debit ?? $line->debit_amount);
        $credit = (string) ($line->functional_credit ?? $line->credit_amount);
        $foreign = (float) $line->exchange_rate !== 1.0;
        $foreignAmount = Decimal::isZero((string) $line->debit_amount) ? (string) $line->credit_amount : (string) $line->debit_amount;

        return [
            $this->text((string) ($entry->journal_type ?? 'OD')),
            $this->text((string) ($entry->journal_type ?? 'OD')),
            (string) $entry->journal_number,
            $this->date($entry->posting_date),
            $this->text((string) $line->account?->account_code),
            $this->text((string) $line->account?->name),
            $partner['number'] ?? '',
            $partner['name'] ?? '',
            $this->text((string) ($entry->getAttribute('document_number') ?: $entry->entry_number)),
            $this->date($entry->document_date ?? $entry->posting_date),
            $this->text((string) ($line->description ?: $entry->description)),
            $this->amount($debit),
            $this->amount($credit),
            $lettering['code'] ?? '',
            $lettering['date'] ?? '',
            $this->date($entry->posted_at ?? $entry->posting_date),
            $foreign ? $this->amount($foreignAmount) : '',
            $foreign ? (string) $line->currency : '',
        ];
    }

    /**
     * @param  iterable<JournalEntry>  $entries
     * @return array<string, array{number: string, name: string}>
     */
    private function partners(string $tenantId, iterable $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                if ($line->partner_id !== null) {
                    $ids[(string) $line->partner_id] = true;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $result = [];

        foreach (DB::table('contacts')->where('tenant_id', $tenantId)->whereIn('id', array_keys($ids))->get(['id', 'company_name', 'first_name', 'last_name']) as $contact) {
            $result[(string) $contact->id] = [
                'number' => 'T'.strtoupper(substr(str_replace('-', '', (string) $contact->id), 0, 12)),
                'name' => $this->text((string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name))),
            ];
        }

        return $result;
    }

    private function siren(string $tenantId): string
    {
        $tenant = Tenant::query()->find($tenantId);
        $digits = preg_replace('/\D/', '', (string) ($tenant?->fiscal_code ?: $tenant?->vat_number)) ?? '';

        return strlen($digits) >= 9 ? substr($digits, -9) : str_pad($digits, 9, '0', STR_PAD_LEFT);
    }

    private function date(mixed $value): string
    {
        return $value === null ? '' : Carbon::parse($value)->format('Ymd');
    }

    private function amount(string $value): string
    {
        return str_replace('.', ',', Decimal::round2(Decimal::abs($value)));
    }

    /**
     * A text field must not contain the separator or a line break.
     */
    private function text(string $value): string
    {
        return trim(preg_replace('/[\t\r\n]+/', ' ', $value) ?? '');
    }
}
