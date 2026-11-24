<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Models\JournalLineLettering;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Lettering (lettrage) of ledger lines: debits and credits of one account and partner that cancel out get a shared
 * code. Codes are AA, AB, ... AZ, BA, ... ZZ, then AAA and on, in order of creation per tenant. The lettering is
 * written to FEC columns EcritureLet and DateLet.
 */
final class JournalLetteringService
{
    /**
     * @param  list<string>  $lineIds
     * @return array{lettering_code: string, lettered_on: string, lines: int}
     */
    public function letter(string $tenantId, array $lineIds, string $letteredOn): array
    {
        $lineIds = array_values(array_unique($lineIds));

        return DB::transaction(function () use ($tenantId, $lineIds, $letteredOn): array {
            $lines = JournalLine::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $lineIds)->get();

            if ($lines->count() !== count($lineIds) || $lines->count() < 2) {
                throw ValidationException::withMessages(['journal_line_ids' => [__('validation.lettering_lines_invalid')]]);
            }

            $postedEntries = JournalEntry::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $lines->pluck('journal_entry_id')->unique()->all())
                ->whereIn('status', ['posted', 'reversed'])
                ->count();

            if ($postedEntries !== $lines->pluck('journal_entry_id')->unique()->count()) {
                throw ValidationException::withMessages(['journal_line_ids' => [__('validation.lettering_lines_not_posted')]]);
            }

            if ($lines->pluck('account_id')->unique()->count() !== 1 || $lines->pluck('partner_id')->unique()->count() !== 1) {
                throw ValidationException::withMessages(['journal_line_ids' => [__('validation.lettering_lines_mixed')]]);
            }

            if (JournalLineLettering::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('journal_line_id', $lineIds)->exists()) {
                throw ValidationException::withMessages(['journal_line_ids' => [__('validation.lettering_lines_already_lettered')]]);
            }

            $balance = '0';

            foreach ($lines as $line) {
                $balance = Decimal::add($balance, Decimal::sub((string) ($line->functional_debit ?? $line->debit_amount), (string) ($line->functional_credit ?? $line->credit_amount)));
            }

            if (! Decimal::isZero(Decimal::round2($balance))) {
                throw ValidationException::withMessages(['journal_line_ids' => [__('validation.lettering_not_balanced')]]);
            }

            $code = $this->nextCode($tenantId);

            foreach ($lines as $line) {
                JournalLineLettering::create([
                    'tenant_id' => $tenantId,
                    'journal_line_id' => $line->id,
                    'account_id' => $line->account_id,
                    'lettering_code' => $code,
                    'lettered_on' => $letteredOn,
                ]);
            }

            return ['lettering_code' => $code, 'lettered_on' => $letteredOn, 'lines' => $lines->count()];
        });
    }

    /**
     * Removes a lettering; returns the number of lines released.
     */
    public function unletter(string $tenantId, string $code): int
    {
        return JournalLineLettering::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('lettering_code', $code)
            ->forceDelete();
    }

    /**
     * @return list<array{lettering_code: string, lettered_on: string, account_id: string, line_ids: list<string>}>
     */
    public function list(string $tenantId, ?string $accountId = null): array
    {
        $rows = JournalLineLettering::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($accountId !== null, static fn ($query) => $query->where('account_id', $accountId))
            ->orderBy('lettering_code')
            ->get();

        return $rows->groupBy('lettering_code')->map(static fn ($group, $code): array => [
            'lettering_code' => (string) $code,
            'lettered_on' => $group->first()->lettered_on->toDateString(),
            'account_id' => (string) $group->first()->account_id,
            'line_ids' => $group->pluck('journal_line_id')->map(strval(...))->values()->all(),
        ])->values()->all();
    }

    /**
     * @param  list<string>  $lineIds
     * @return array<string, array{code: string, date: string}>
     */
    public function forLines(string $tenantId, array $lineIds): array
    {
        $result = [];

        foreach (array_chunk($lineIds, 500) as $chunk) {
            foreach (JournalLineLettering::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('journal_line_id', $chunk)->get() as $row) {
                $result[(string) $row->journal_line_id] = ['code' => (string) $row->lettering_code, 'date' => $row->lettered_on->format('Ymd')];
            }
        }

        return $result;
    }

    private function nextCode(string $tenantId): string
    {
        $max = -1;

        foreach (JournalLineLettering::withoutGlobalScopes()->where('tenant_id', $tenantId)->distinct()->pluck('lettering_code') as $code) {
            $max = max($max, $this->decode((string) $code));
        }

        return $this->encode($max + 1);
    }

    private function encode(int $index): string
    {
        $length = 2;

        while ($index >= 26 ** $length) {
            $index -= 26 ** $length;
            $length++;
        }

        $letters = '';

        for ($position = 0; $position < $length; $position++) {
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    private function decode(string $code): int
    {
        $offset = 0;

        for ($step = 2; $step < strlen($code); $step++) {
            $offset += 26 ** $step;
        }

        $value = 0;

        foreach (str_split($code) as $letter) {
            $value = $value * 26 + (ord($letter) - 65);
        }

        return $offset + $value;
    }
}
