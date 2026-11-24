<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Erasure;

use App\Enums\Privacy\DsarSubjectType;
use App\Services\Privacy\PersonalDataAnonymizer;
use App\Services\Privacy\PersonalDataEntry;
use App\Services\Privacy\PersonalDataRegistry;
use App\Services\Privacy\PersonalDataRetention;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketMessage;

/**
 * Selective cryptographic anonymisation: personal columns of the non-fiscal tables of a subject are overwritten with
 * an irreversible keyed hash (ANON-<SHA256 prefix>); fiscal and payroll records are never touched.
 */
final readonly class SelectiveAnonymizationService
{
    public function __construct(
        private PersonalDataRegistry $registry,
        private PersonalDataAnonymizer $anonymizer,
    ) {}

    /**
     * Irreversible replacement value of a column: keyed SHA-256, so it cannot be reversed or rainbow-tabled.
     *
     * @param  array{type: string, nullable: bool, length: int|null}  $meta
     */
    public static function hashValue(string $column, string $rowId, array $meta): string
    {
        $prefix = strtoupper(substr(hash_hmac('sha256', $rowId.'|'.$column, (string) config('app.key')), 0, 16));
        $value = str_contains($column, 'email') ? "ANON-{$prefix}@redacted.invalid" : "ANON-{$prefix}";

        return $meta['length'] !== null && $meta['length'] < strlen($value) ? substr($value, 0, $meta['length']) : $value;
    }

    /**
     * Tables whose rows the subject's erasure would anonymise, with the rows still holding personal data.
     *
     * @return list<array{table: string, label: string, module: string, rows: int}>
     */
    public function preview(string $tenantId, DsarSubjectType $type, string $entityId, bool $fiscalLocked): array
    {
        $out = [];
        foreach ($this->targets($tenantId, $type, $entityId, $fiscalLocked) as $target) {
            $rows = count($this->anonymizer->pendingIds($target['entry'], $target['scope'], null, self::neutralizer(...)));
            $out[] = ['table' => $target['entry']->table, 'label' => $target['entry']->label, 'module' => $target['module'], 'rows' => $rows];
        }

        return $out;
    }

    /**
     * @return list<array{table: string, label: string, module: string, rows: int}>
     */
    public function execute(string $tenantId, DsarSubjectType $type, string $entityId, bool $fiscalLocked): array
    {
        $out = [];
        foreach ($this->targets($tenantId, $type, $entityId, $fiscalLocked) as $target) {
            $result = $this->anonymizer->anonymize($target['entry'], $target['scope'], null, self::neutralizer(...));
            $out[] = ['table' => $target['entry']->table, 'label' => $target['entry']->label, 'module' => $target['module'], 'rows' => $result['rows']];
        }

        return $out;
    }

    /**
     * Anonymises the rows of one retention rule table that are older than the cutoff.
     *
     * @param  Closure(Builder): mixed  $scope
     * @return array{evaluated: int, anonymized: int}
     */
    public function anonymizeScoped(PersonalDataEntry $entry, Closure $scope, bool $dryRun): array
    {
        $pending = $this->anonymizer->pendingIds($entry, $scope, null, self::neutralizer(...));
        if (! $dryRun && $pending !== []) {
            $this->anonymizer->anonymize($entry, static fn (Builder $query) => $query->whereIn('id', $pending), null, self::neutralizer(...));
        }

        return ['evaluated' => count($pending), 'anonymized' => $dryRun ? 0 : count($pending)];
    }

    public function helpdeskTicketEntry(): PersonalDataEntry
    {
        return new PersonalDataEntry('esm_tickets', [Ticket::class], 'Helpdesk tickets (Mod 050)', 'contact', ['subject', 'description'], PersonalDataRetention::Erasable, 'Support history of the subject.', ['contact' => 'contact_id'], 'updated_at');
    }

    private function ticketMessageEntry(): PersonalDataEntry
    {
        return new PersonalDataEntry('esm_ticket_messages', [TicketMessage::class], 'Helpdesk ticket messages (Mod 050)', 'contact', ['author_name', 'author_email', 'body'], PersonalDataRetention::Erasable, 'Support transcripts of the subject.', [], 'created_at');
    }

    /**
     * @param  array{type: string, nullable: bool, length: int|null}  $meta
     */
    private static function neutralizer(string $column, string $rowId, array $meta): string
    {
        return self::hashValue($column, $rowId, $meta);
    }

    /**
     * @return list<array{entry: PersonalDataEntry, scope: Closure, module: string}>
     */
    private function targets(string $tenantId, DsarSubjectType $type, string $entityId, bool $fiscalLocked): array
    {
        $targets = [];
        $registryType = $type->registryType();

        foreach ($registryType === null ? [] : $this->registry->entriesForSubject($registryType) as $entry) {
            if (! $entry->retention->isErasable() || ! Schema::hasTable($entry->table)) {
                continue;
            }

            // The identity of a customer stays while its invoices are locked: it is a legal identifier of the invoice.
            if ($fiscalLocked && in_array($entry->table, ['contacts', 'contact_addresses'], true)) {
                continue;
            }

            $column = $entry->subjectLinks[$registryType];
            $targets[] = [
                'entry' => $entry,
                'scope' => static function (Builder $query) use ($entry, $tenantId, $column, $entityId): void {
                    if ($entry->hasTenantColumn) {
                        $query->where('tenant_id', $tenantId);
                    }

                    $query->where($column, $entityId);
                },
                'module' => $this->moduleOf($entry->table),
            ];
        }

        if ($type === DsarSubjectType::CustomerContact && Schema::hasTable('esm_tickets')) {
            $ticketScope = static fn (Builder $query) => $query->where('tenant_id', $tenantId)->where('contact_id', $entityId);
            $targets[] = ['entry' => $this->helpdeskTicketEntry(), 'scope' => $ticketScope, 'module' => 'MOD_050_HELPDESK'];
            $targets[] = [
                'entry' => $this->ticketMessageEntry(),
                'scope' => static fn (Builder $query) => $query->where('tenant_id', $tenantId)->whereIn('ticket_id', DB::table('esm_tickets')->where('tenant_id', $tenantId)->where('contact_id', $entityId)->select('id')),
                'module' => 'MOD_050_HELPDESK',
            ];
        }

        if ($type === DsarSubjectType::SupplierContact && ($entry = $this->registry->find('supplier_contacts')) !== null && Schema::hasTable('supplier_contacts')) {
            $targets[] = [
                'entry' => $entry,
                'scope' => static fn (Builder $query) => $query->where('tenant_id', $tenantId)->where('id', $entityId),
                'module' => 'MOD_013_PROCUREMENT',
            ];
        }

        return $targets;
    }

    private function moduleOf(string $table): string
    {
        return match (true) {
            str_starts_with($table, 'crm_') => 'MOD_034_LEADS',
            str_starts_with($table, 'hr_') => 'MOD_070_CANDIDATES',
            str_starts_with($table, 'esm_') => 'MOD_050_HELPDESK',
            default => 'MOD_002_CRM',
        };
    }
}
